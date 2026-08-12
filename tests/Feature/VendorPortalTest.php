<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $pwId, string $name, ?string $phone = null, ?string $email = null): Vendor
    {
        $user = User::factory()->create($phone ? ['phone' => $phone] : []);

        return Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => $name,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
            'email' => $email,
        ]);
    }

    private function makeWorkOrder(): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
            'description' => 'Leaking kitchen sink',
        ]);
    }

    public function test_coordinator_sees_copyable_vendor_links_on_work_order(): void
    {
        Role::findOrCreate('woc', 'web');

        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $vendor->update(['portal_token' => 'vendor-acme']);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->actingAs($woc)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('vendorLinks', 1)
                ->where('vendorLinks.0.url', route('vendor.portal.show', 'token-acme'))
                ->where('vendorLinks.0.dashboard_url', route('vendor.portal.dashboard', 'vendor-acme'))
            );
    }

    public function test_imported_assignment_with_no_token_still_gets_a_copyable_link(): void
    {
        Role::findOrCreate('woc', 'web');

        $woc = User::factory()->create();
        $woc->assignRole('woc');

        // Vendors attached by the PropertyWare import never got a token, which
        // used to leave the coordinator with a dead "This job" button.
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => null]);

        $this->actingAs($woc)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('vendorLinks', 1)
                ->where('vendorLinks.0.url', fn ($url) => filled($url))
            );

        $this->assertNotEmpty(
            $workOrder->vendors()->first()->pivot->access_token,
            'The link should be backed by a real token.',
        );
    }

    public function test_dashboard_lists_only_that_vendors_open_work_orders(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $vendor->update(['portal_token' => 'vendor-acme']);

        $openWo = $this->makeWorkOrder();
        $openWo->update(['status' => 'Open']);
        $openWo->vendors()->attach($vendor->id, ['access_token' => 'token-open']);

        $closedWo = WorkOrder::factory()->create([
            'service_status_id' => $openWo->service_status_id,
            'work_order_no' => 9999,
            'status' => 'Closed',
        ]);
        $closedWo->vendors()->attach($vendor->id, ['access_token' => 'token-closed']);

        $this->get(route('vendor.portal.dashboard', 'vendor-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendorPortal/Dashboard')
                ->where('vendorName', 'Acme Plumbing')
                ->has('workOrders', 1)
                ->where('workOrders.0.work_order_no', 4567)
                ->where('workOrders.0.url', route('vendor.portal.show', 'token-open'))
            );
    }

    public function test_dashboard_invalid_token_returns_404(): void
    {
        $this->get(route('vendor.portal.dashboard', 'nope'))
            ->assertNotFound();
    }

    public function test_dashboard_excludes_other_vendors_jobs(): void
    {
        $acme = $this->makeVendor('V-1', 'Acme Plumbing');
        $acme->update(['portal_token' => 'vendor-acme']);
        $beta = $this->makeVendor('V-2', 'Beta Electric');

        $betaWo = $this->makeWorkOrder();
        $betaWo->update(['status' => 'Open']);
        $betaWo->vendors()->attach($beta->id, ['access_token' => 'token-beta']);

        $this->get(route('vendor.portal.dashboard', 'vendor-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workOrders', 0));
    }

    public function test_portal_page_exposes_dashboard_home_url(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $vendor->update(['portal_token' => 'vendor-acme']);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboardUrl', route('vendor.portal.dashboard', 'vendor-acme'))
            );
    }

    public function test_valid_token_renders_portal_for_that_vendor(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendorPortal/Show')
                ->where('vendorName', 'Acme Plumbing')
                ->where('workOrder.work_order_no', 4567)
            );
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->get(route('vendor.portal.show', 'does-not-exist'))
            ->assertNotFound();
    }

    public function test_magic_link_works_while_logged_in_as_an_unrelated_vendor(): void
    {
        // The public portal is gated only by its token. A different vendor being
        // authenticated in the same browser must not affect resolution — the
        // WorkOrder global scope (which restricts a logged-in vendor to their own
        // work orders) must not leak into the token lookup and 404 the page.
        Role::findOrCreate('vendor', 'web');

        $assigned = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($assigned->id, ['access_token' => 'token-acme']);

        $intruder = $this->makeVendor('V-2', 'Beta Electric');
        $intruder->user->assignRole('vendor');

        $this->actingAs($intruder->user)
            ->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendorPortal/Show')
                ->where('vendorName', 'Acme Plumbing')
                ->where('workOrder.work_order_no', 4567)
            );
    }

    public function test_one_vendor_token_never_exposes_another_vendor(): void
    {
        $acme = $this->makeVendor('V-1', 'Acme Plumbing');
        $other = $this->makeVendor('V-2', 'Beta Electric');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($acme->id, ['access_token' => 'token-acme']);
        $workOrder->vendors()->attach($other->id, ['access_token' => 'token-beta']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vendorName', 'Acme Plumbing'))
            ->assertDontSee('Beta Electric');
    }

    public function test_vendor_only_sees_their_own_coordinator_thread(): void
    {
        $acme = $this->makeVendor('V-1', 'Acme Plumbing', '5125550001');
        $other = $this->makeVendor('V-2', 'Beta Electric', '5125550002');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($acme->id, ['access_token' => 'token-acme']);
        $workOrder->vendors()->attach($other->id, ['access_token' => 'token-beta']);

        Conversation::create([
            'message' => 'Acme private message',
            'sender_number' => '+15125550001',
            'receiver_number' => '+15120000000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
        ]);
        Conversation::create([
            'message' => 'Beta private message',
            'sender_number' => '+15125550002',
            'receiver_number' => '+15120000000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
        ]);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('messages', 1))
            ->assertSee('Acme private message')
            ->assertDontSee('Beta private message');
    }

    public function test_vendor_can_save_estimate(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.estimate', 'token-acme'), [
            'cost_estimate' => 250.50,
            'time_estimate' => 3,
            'scheduled_end_date' => '2026-07-01',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'cost_estimate' => 250.50,
            'time_estimate' => 3,
        ]);
    }

    public function test_vendor_can_upload_photo(): void
    {
        Storage::fake('public');
        Queue::fake();

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.attachments', 'token-acme'), [
            'title' => 'Kitchen leak - after repair',
            'type' => 'after',
            'files' => [UploadedFile::fake()->image('repair.jpg')],
        ])->assertRedirect();

        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'user_id' => $vendor->user_id,
            'type' => 'after',
            'title' => 'Kitchen leak - after repair',
            'is_publish_to_owner_portal' => true,
            'is_publish_to_tenant_portal' => true,
        ]);

        Queue::assertPushed(UploadAttachment::class);
    }

    public function test_vendor_can_upload_photo_without_a_description(): void
    {
        Storage::fake('public');
        Queue::fake();

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.attachments', 'token-acme'), [
            'type' => 'before',
            'files' => [UploadedFile::fake()->image('leak.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'user_id' => $vendor->user_id,
            'type' => 'before',
            'title' => 'Vendor photo (before) - WO#4567',
        ]);
    }

    public function test_blank_description_falls_back_to_a_generated_title(): void
    {
        Storage::fake('public');
        Queue::fake();

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.attachments', 'token-acme'), [
            'title' => '   ',
            'type' => 'attachment',
            'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'title' => 'Vendor attachment - WO#4567',
        ]);
    }

    public function test_portal_shows_only_tasks_assigned_to_the_vendor(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $other = User::factory()->create();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
            'description' => 'My assigned task',
            'status' => 'pending',
        ]);
        WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $other->id,
            'description' => 'Someone else task',
            'status' => 'pending',
        ]);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.name', 'My assigned task')
            )
            ->assertDontSee('Someone else task');
    }

    public function test_vendor_can_complete_their_assigned_task(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $task = WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
            'description' => 'Replace valve',
            'status' => 'pending',
        ]);

        $this->post(route('vendor.portal.tasks.complete', ['token' => 'token-acme', 'task' => $task->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('work_order_tasks', [
            'id' => $task->id,
            'status' => 'completed',
        ]);
    }

    public function test_vendor_cannot_complete_a_task_not_assigned_to_them(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $other = User::factory()->create();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $task = WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $other->id,
            'description' => 'Not yours',
            'status' => 'pending',
        ]);

        $this->post(route('vendor.portal.tasks.complete', ['token' => 'token-acme', 'task' => $task->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('work_order_tasks', [
            'id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_coordinator_can_message_vendor_without_a_phone_number(): void
    {
        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing'); // no phone
        $vendor->update(['portal_token' => 'vendor-acme']);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->actingAs($woc)->post(route('work_order.conversation.send'), [
            'text' => 'Please call the tenant first.',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
            'vendor_id' => $vendor->id,
            // no receiver_phone_number on purpose
        ])->assertRedirect();

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'message' => 'Please call the tenant first.',
            'read_by_vendor' => false,
        ]);
    }

    public function test_portal_shows_unread_message_for_numberless_vendor(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing'); // no phone
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        Conversation::create([
            'message' => 'Coordinator message',
            'sender_number' => '+15120000000',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'read_by_vendor' => false,
        ]);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unreadMessages', 1)
                ->has('messages', 1)
                ->where('messages.0.is_from_vendor', false)
            )
            ->assertSee('Coordinator message');
    }

    public function test_marking_messages_read_clears_the_badge(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $conversation = Conversation::create([
            'message' => 'Coordinator message',
            'sender_number' => '+15120000000',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'read_by_vendor' => false,
        ]);

        $this->post(route('vendor.portal.messages.read', 'token-acme'))->assertRedirect();

        $this->assertDatabaseHas('work_order_conversations', [
            'id' => $conversation->id,
            'read_by_vendor' => true,
        ]);
    }

    public function test_vendor_can_create_a_service_schedule_attributed_to_them(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.schedule', 'token-acme'), [
            'title' => 'Service Schedule for 4567',
            'date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'description' => 'Fix exhaust fan',
        ])->assertRedirect();

        $this->assertDatabaseHas('service_schedules', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'title' => 'Service Schedule for 4567',
            'status' => 'scheduled',
        ]);
    }

    public function test_vendor_can_upload_invoice(): void
    {
        Storage::fake('public');
        Http::fake();

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.invoice', 'token-acme'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'filename' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'amount' => '325.00',
            'status' => 'approved',
        ]);
    }

    public function test_vendor_message_is_stored_unread_for_coordinator(): void
    {
        $vendor = $this->makeVendor('V-1', 'Acme Plumbing', '5125550001');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.message', 'token-acme'), [
            'text' => 'On my way now',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
            'message' => 'On my way now',
            'is_read' => false,
        ]);
    }

    public function test_portal_shows_the_tenant_the_vendor_should_call(): void
    {
        $tenant = Tenants::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'mobile_phone' => '5125550123',
            'home_phone' => '5125550456',
            'email' => 'maria@example.com',
        ]);

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['tenant_id' => $tenant->id]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenantContacts', 1)
                ->where('tenantContacts.0.name', 'Maria Lopez')
                ->where('tenantContacts.0.mobile_phone', '(512) 555-0123')
                ->where('tenantContacts.0.home_phone', '(512) 555-0456')
                ->where('tenantContacts.0.email', 'maria@example.com')
                ->where('tenantContacts.0.is_primary', true)
            );
    }

    public function test_portal_lists_other_tenants_on_the_work_order_too(): void
    {
        $requester = Tenants::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'mobile_phone' => '5125550123',
        ]);
        $roommate = Tenants::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'mobile_phone' => '5125550999',
        ]);

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['tenant_id' => $requester->id]);
        $workOrder->tenants()->attach([$requester->id, $roommate->id]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The requester is listed once, and first.
                ->has('tenantContacts', 2)
                ->where('tenantContacts.0.name', 'Maria Lopez')
                ->where('tenantContacts.0.is_primary', true)
                ->where('tenantContacts.1.name', 'Ana Reyes')
                ->where('tenantContacts.1.is_primary', false)
            );
    }

    public function test_portal_hides_tenant_contact_on_a_vacant_work_order(): void
    {
        $tenant = Tenants::factory()->create(['mobile_phone' => '5125550123']);

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['tenant_id' => $tenant->id, 'skip_automated_tasks' => true]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tenantContacts', 0));
    }

    public function test_portal_omits_a_tenant_with_no_way_to_reach_them(): void
    {
        $tenant = Tenants::factory()->create([
            'mobile_phone' => null,
            'home_phone' => null,
            'work_phone' => null,
            'email' => null,
        ]);

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['tenant_id' => $tenant->id]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->get(route('vendor.portal.show', 'token-acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tenantContacts', 0));
    }

    public function test_work_order_modal_endpoint_returns_vendor_links_for_staff(): void
    {
        Role::findOrCreate('woc', 'web');

        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $vendor = $this->makeVendor('V-1', 'Acme Plumbing');
        $vendor->update(['portal_token' => 'vendor-acme']);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $response = $this->actingAs($woc)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('vendor_links.0.url', route('vendor.portal.show', 'token-acme'))
            ->assertJsonPath('vendor_links.0.dashboard_url', route('vendor.portal.dashboard', 'vendor-acme'));

        // The raw token is what the link is made of and must not ship in the
        // payload, where another vendor could read it out of the page source.
        $this->assertArrayNotHasKey('access_token', $response->json('vendors.0.pivot'));
    }
}
