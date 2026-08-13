<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\TenantPortalLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'address' => '6341 Del Monte Dr',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function easyFixStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'The service request is checking for an easy fix.'],
        );
    }

    private function makeWorkOrder(?Tenants $tenant = null, ?ServiceStatus $status = null): WorkOrder
    {
        $status ??= $this->easyFixStatus();

        return WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43361,
            'description' => 'There is water dripping from the roof down into the backyard',
            'tenant_id' => $tenant?->id,
        ]);
    }

    private function makeToken(WorkOrder $workOrder): TenantUploadToken
    {
        return TenantUploadToken::create([
            'token' => 'demo-tenant-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
        ]);
    }

    public function test_a_bad_token_404s(): void
    {
        $this->get('/tenant-portal/not-a-real-token')->assertNotFound();
    }

    public function test_a_valid_token_renders_the_portal(): void
    {
        $tenant = $this->makeTenant();
        $building = Building::query()->create([
            'propertyware_id' => 'B-6341DM',
            'name' => 'Del Monte',
            'address' => '6341 Del Monte Dr',
            'city' => 'Houston',
            'state_region' => 'TX',
        ]);
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->update(['building_id' => $building->propertyware_id]);
        $token = $this->makeToken($workOrder);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TenantPortal/Show')
                ->where('workOrder.work_order_no', 43361)
                ->where('workOrder.address', '6341 Del Monte Dr')
                ->where('tenantName', 'Dana')
                ->where('completed', false));
    }

    public function test_a_tenant_can_upload_photos_which_completes_the_request(): void
    {
        Queue::fake();
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.attachments', $token->token), [
            'files' => [
                UploadedFile::fake()->image('leak-1.jpg'),
                UploadedFile::fake()->image('leak-2.jpg'),
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('attachments', 2);
        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'uploaded_via_tenant_portal' => true,
            'type' => 'before',
        ]);

        Queue::assertPushed(UploadAttachment::class, 2);

        // Photos received stops the reminders.
        $this->assertNotNull($token->fresh()->completed_at);
    }

    public function test_rejects_non_photo_files(): void
    {
        Storage::fake('public');

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.attachments', $token->token), [
            'files' => [UploadedFile::fake()->create('malware.exe', 100)],
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_the_tenant_can_mark_themselves_done(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.complete', $token->token))->assertRedirect();

        $this->assertNotNull($token->fresh()->completed_at);
    }

    public function test_the_command_sends_the_link_for_easy_fix_work_orders(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $token = TenantUploadToken::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertNotNull($token);
        $this->assertSame(1, $token->notified_count);

        $message = $workOrder->tenant_conversation()->firstOrFail();
        $this->assertStringContainsString('upload photos', $message->message);
        $this->assertStringContainsString($token->token, $message->message);
        $this->assertStringContainsString('(Ref: WO#43361)', $message->message);
        $this->assertSame('+15125559999', $message->receiver_number);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_the_command_is_silent_when_the_gate_is_off(): void
    {
        // Gate defaults to off.
        Queue::fake();

        $this->makeWorkOrder($this->makeTenant());

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertDatabaseCount('tenant_upload_tokens', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_the_link_is_sent_only_once_per_work_order(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());

        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $workOrder->id)->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_a_reminder_goes_out_after_two_weekdays_and_is_capped(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);
        $token->update(['notified_count' => 1, 'last_notified_at' => now()->subWeekdays(3)]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(2, $token->fresh()->notified_count);
        Queue::assertPushed(SendConversationMessageJob::class, 1);

        // At the cap, no further reminders even when stale again.
        $token->update(['notified_count' => 3, 'last_notified_at' => now()->subWeekdays(5)]);
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertSame(3, $token->fresh()->notified_count);
    }

    public function test_no_link_or_token_for_a_property_with_no_lease_on_file(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $workOrder->update(['propertyware_id' => 43485001, 'lease_id' => null]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        // No token is claimed, so importing the lease data later re-arms the link.
        $this->assertDatabaseCount('tenant_upload_tokens', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_no_reminder_for_a_property_with_no_lease_on_file(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);
        $token->update(['notified_count' => 1, 'last_notified_at' => now()->subWeekdays(3)]);
        $workOrder->update(['propertyware_id' => 43485001, 'lease_id' => null]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(1, $token->fresh()->notified_count);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_a_completed_request_gets_no_reminder(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);
        $token->update([
            'notified_count' => 1,
            'last_notified_at' => now()->subWeekdays(3),
            'completed_at' => now()->subDay(),
        ]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(1, $token->fresh()->notified_count);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    // --- Portal parity with the owner portal --------------------------------

    private function tenantMessage(WorkOrder $workOrder, string $message, string $sender): Conversation
    {
        return Conversation::create([
            'message' => $message,
            'sender_number' => $sender,
            'receiver_number' => '+15125559999',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'read_by_tenant' => false,
            'is_mms' => false,
        ]);
    }

    public function test_the_portal_shows_the_tenant_thread_and_the_appointment(): void
    {
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        ServiceSchedule::query()->create([
            'title' => 'Roof leak',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        // One message from the coordinator, one from the tenant's own number.
        $this->tenantMessage($workOrder, 'We have your request.', '+12813787957');
        $this->tenantMessage($workOrder, 'Thanks!', '+15125559999');

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TenantPortal/Show')
                ->has('messages', 2)
                ->where('messages.0.from_tenant', false)
                ->where('messages.1.from_tenant', true)
                ->where('unreadMessages', 2)
                ->has('workOrder.appointment'));
    }

    public function test_the_portal_never_leaks_the_owner_or_vendor_threads(): void
    {
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $this->tenantMessage($workOrder, 'Tenant thread message.', '+12813787957');

        foreach (['owner', 'vendor', 'vendor_tenant', 'vendor_owner'] as $type) {
            Conversation::create([
                'message' => 'Private '.$type.' message.',
                'sender_number' => '+12813787957',
                'receiver_number' => '+15125550000',
                'work_order_id' => $workOrder->id,
                'conversation_type' => $type,
                'is_read' => true,
                'is_mms' => false,
            ]);
        }

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.message', 'Tenant thread message.'))
            ->assertDontSee('Private owner message.')
            ->assertDontSee('Private vendor message.');
    }

    public function test_the_gallery_shows_tenant_photos_but_not_internal_files(): void
    {
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        Attachments::query()->create([
            'title' => 'Tenant photo',
            'filename' => 'attachments/tenant.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $tenant->user_id,
            'uploaded_via_tenant_portal' => true,
            'is_publish_to_tenant_portal' => true,
        ]);

        Attachments::query()->create([
            'title' => 'Internal vendor invoice',
            'filename' => 'attachments/internal.pdf',
            'filetype' => 'application/pdf',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'uploaded_via_tenant_portal' => false,
            'is_publish_to_tenant_portal' => false,
        ]);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 1)
                ->where('attachments.0.title', 'Tenant photo')
                ->where('attachments.0.source', 'From you'));
    }

    public function test_a_tenant_can_message_their_coordinator(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.message', $token->token), [
            'text' => 'The leak is getting worse.',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'message' => 'The leak is getting worse.',
            // Inbound: it lands unread in the coordinator's tenant tab.
            'is_read' => false,
        ]);

        // No SMS is sent — the coordinator reads it inside the system.
        Queue::assertNotPushed(SendConversationMessageJob::class);

        // Engaging stops the schedule follow-up.
        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.message', $token->token), ['text' => '   '])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseCount('work_order_conversations', 0);
    }

    public function test_marking_messages_read_clears_the_badge(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $message = $this->tenantMessage($workOrder, 'An update for you.', '+12813787957');

        $this->post(route('tenant.portal.messages.read', $token->token))->assertRedirect();

        $this->assertTrue((bool) $message->fresh()->read_by_tenant);
    }

    public function test_uploading_photos_also_marks_the_tenant_as_engaged(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.attachments', $token->token), [
            'files' => [UploadedFile::fake()->image('leak.jpg')],
        ])->assertRedirect();

        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_a_general_work_order_token_opens_the_same_portal(): void
    {
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);

        $link = app(TenantPortalLinkService::class)->link($workOrder);

        $this->assertNotNull($link);

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->firstOrFail();

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TenantPortal/Show')
                ->where('token', $token->token)
                // The general link is not an HOA notice.
                ->where('isHoa', false));
    }

    public function test_the_general_token_is_reused_across_calls(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $service = app(TenantPortalLinkService::class);

        $first = $service->link($workOrder);
        $second = $service->link($workOrder);

        $this->assertSame($first, $second);
        $this->assertSame(1, TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->count());
    }

    public function test_the_general_token_is_separate_from_the_easy_fix_token(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $easyFix = $this->makeToken($workOrder);

        $general = app(TenantPortalLinkService::class)->tokenFor($workOrder);

        $this->assertNotSame($easyFix->token, $general->token);
        $this->assertSame(TenantUploadToken::PURPOSE_WORK_ORDER, $general->purpose);

        // Both still resolve — a live easy-fix link keeps working.
        $this->get(route('tenant.portal.show', $easyFix->token))->assertOk();
        $this->get(route('tenant.portal.show', $general->token))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Report a new issue
    |--------------------------------------------------------------------------
    */

    /**
     * A portal link whose work order sits on a real building, with the feature
     * on and PropertyWare creation off so nothing leaves the app.
     *
     * @return array{0: WorkOrder, 1: TenantUploadToken}
     */
    private function portalReadyForNewRequests(): array
    {
        config([
            'services.tenant_portal.create_request_enabled' => true,
            'services.tenant_portal.pw_create_enabled' => false,
        ]);

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New request.']);

        $building = Building::query()->create([
            'propertyware_id' => 'B-6341DM',
            'name' => 'Del Monte',
            'address' => '6341 Del Monte Dr',
        ]);

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $workOrder->update(['building_id' => $building->propertyware_id]);

        return [$workOrder, $this->makeToken($workOrder)];
    }

    public function test_the_portal_exposes_whether_a_new_request_can_be_created(): void
    {
        [$workOrder, $token] = $this->portalReadyForNewRequests();

        $this->get(route('tenant.portal.show', $token->token))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateRequest', true));

        config(['services.tenant_portal.create_request_enabled' => false]);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateRequest', false));

        // No PropertyWare building means there is nothing to hang a request on.
        config(['services.tenant_portal.create_request_enabled' => true]);
        $workOrder->update(['building_id' => null]);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateRequest', false));
    }

    public function test_a_new_request_needs_a_real_description(): void
    {
        [, $token] = $this->portalReadyForNewRequests();
        $before = WorkOrder::query()->count();

        $this->post(route('tenant.portal.request.store', $token->token), ['description' => ''])
            ->assertSessionHasErrors('description');

        $this->post(route('tenant.portal.request.store', $token->token), ['description' => 'broken'])
            ->assertSessionHasErrors('description');

        $this->assertSame($before, WorkOrder::query()->count());
    }

    public function test_a_new_request_rejects_too_many_photos_and_bad_file_types(): void
    {
        Storage::fake('public');
        [, $token] = $this->portalReadyForNewRequests();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
            'photos' => array_map(
                fn (int $i) => UploadedFile::fake()->image("photo{$i}.jpg"),
                range(1, 11),
            ),
        ])->assertSessionHasErrors('photos');

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
            'photos' => [UploadedFile::fake()->create('script.exe', 10)],
        ])->assertSessionHasErrors('photos.0');
    }

    public function test_a_new_request_is_refused_when_the_feature_is_off(): void
    {
        [, $token] = $this->portalReadyForNewRequests();
        config(['services.tenant_portal.create_request_enabled' => false]);
        $before = WorkOrder::query()->count();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasErrors('description');

        $this->assertSame($before, WorkOrder::query()->count());
    }

    public function test_a_successful_request_redirects_to_the_new_work_orders_portal(): void
    {
        Queue::fake();
        [$source, $token] = $this->portalReadyForNewRequests();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasNoErrors();

        $new = WorkOrder::query()->where('source', 'Tenant Portal')->firstOrFail();
        $this->assertNotSame($source->id, $new->id);

        $newToken = TenantUploadToken::query()
            ->where('work_order_id', $new->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->firstOrFail();

        $this->assertNotSame($token->token, $newToken->token);

        // The tenant lands on a page that IS their new request.
        $this->get(route('tenant.portal.show', $newToken->token))->assertOk();
    }

    public function test_a_new_request_is_recorded_on_the_work_order_the_tenant_came_in_on(): void
    {
        Queue::fake();
        [$source, $token] = $this->portalReadyForNewRequests();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $source->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
        ]);

        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_a_second_request_inside_the_cooldown_is_refused(): void
    {
        Queue::fake();
        [, $token] = $this->portalReadyForNewRequests();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasNoErrors();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The garage door will not close all the way any more.',
        ])->assertSessionHasErrors('description');

        $this->assertSame(1, WorkOrder::query()->where('source', 'Tenant Portal')->count());

        // Past the cooldown the same link works again.
        $this->travel(11)->minutes();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The garage door will not close all the way any more.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, WorkOrder::query()->where('source', 'Tenant Portal')->count());
    }

    public function test_the_same_description_twice_in_a_day_is_refused(): void
    {
        Queue::fake();
        [, $token] = $this->portalReadyForNewRequests();
        config(['services.tenant_portal.request_cooldown_minutes' => 0]);

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasNoErrors();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => '  The kitchen faucet has been   dripping for three days. ',
        ])->assertSessionHasErrors('description');

        $this->assertSame(1, WorkOrder::query()->where('source', 'Tenant Portal')->count());
    }

    public function test_the_open_request_cap_for_one_property_is_enforced(): void
    {
        Queue::fake();
        [, $token] = $this->portalReadyForNewRequests();
        config([
            'services.tenant_portal.request_cooldown_minutes' => 0,
            'services.tenant_portal.max_open_requests' => 1,
        ]);

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertSessionHasNoErrors();

        $this->post(route('tenant.portal.request.store', $token->token), [
            'description' => 'The garage door will not close all the way any more.',
        ])->assertSessionHasErrors('description');

        $this->assertSame(1, WorkOrder::query()->where('source', 'Tenant Portal')->count());
    }

    public function test_a_bad_token_cannot_open_a_request(): void
    {
        config(['services.tenant_portal.create_request_enabled' => true]);

        $this->post('/tenant-portal/not-a-real-token/request', [
            'description' => 'The kitchen faucet has been dripping for three days.',
        ])->assertNotFound();
    }
}
