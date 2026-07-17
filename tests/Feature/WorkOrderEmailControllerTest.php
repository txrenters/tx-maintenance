<?php

namespace Tests\Feature;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\OwnerEmailNotification;
use App\Models\TenantEmailNotification;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrderEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('vendor', 'web');
    }

    public function test_index_returns_only_the_requested_assigned_vendor_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $vendorA = $this->assignedVendor($workOrder, 'a@example.com');
        $vendorB = $this->assignedVendor($workOrder, 'b@example.com');

        EmailMessage::factory()->create(['work_order_id' => $workOrder->id, 'vendor_id' => $vendorA->id, 'subject' => 'A msg']);
        EmailMessage::factory()->create(['work_order_id' => $workOrder->id, 'vendor_id' => $vendorB->id, 'subject' => 'B msg']);

        $response = $this->actingAs($this->woc())
            ->getJson(route('work_order.email.index', $workOrder).'?vendor_id='.$vendorA->id);

        $response->assertOk();
        $subjects = collect($response->json('vendor_emails'))->pluck('subject');
        $this->assertTrue($subjects->contains('A msg'));
        $this->assertFalse($subjects->contains('B msg'));
    }

    public function test_index_rejects_a_vendor_not_assigned_to_the_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $otherWorkOrder = WorkOrder::factory()->create();
        $vendor = $this->assignedVendor($otherWorkOrder);

        $this->actingAs($this->woc())
            ->getJson(route('work_order.email.index', $workOrder).'?vendor_id='.$vendor->id)
            ->assertNotFound();
    }

    public function test_notifications_returns_only_emails_linked_to_the_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $otherWorkOrder = WorkOrder::factory()->create();
        $vendor = $this->assignedVendor($workOrder);

        EmailMessage::factory()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'subject' => 'Vendor update',
        ]);
        OwnerEmailNotification::factory()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Owner update',
        ]);
        TenantEmailNotification::factory()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Tenant update',
        ]);
        TenantEmailNotification::factory()->create([
            'work_order_id' => $otherWorkOrder->id,
            'subject' => 'Unrelated tenant update',
        ]);

        $response = $this->actingAs($this->woc())
            ->getJson(route('work_order.email.notifications', $workOrder));

        $response->assertOk()
            ->assertJsonCount(3, 'emails');

        $subjects = collect($response->json('emails'))->pluck('subject');

        $this->assertEqualsCanonicalizing(
            ['Vendor update', 'Owner update', 'Tenant update'],
            $subjects->all(),
        );
        $this->assertFalse($subjects->contains('Unrelated tenant update'));
    }

    public function test_store_sends_and_persists_outbound_email(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'GID', 'internetMessageId' => '<x@x>', 'conversationId' => 'CID']),
        ]);
        Storage::fake('local');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 777]);
        $vendor = $this->assignedVendor($workOrder);

        $response = $this->actingAs($this->woc())
            ->post(route('work_order.email.send', $workOrder), [
                'vendor_id' => $vendor->id,
                'from_email' => 'coordinator@texasrenters.com',
                'to' => 'vendor-test@example.com',
                'subject' => 'Following up',
                'body' => '<p>Any update?</p>',
                'attachments' => [UploadedFile::fake()->create('note.pdf', 5, 'application/pdf')],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TX-777-'.$vendor->id,
        ]);
    }

    public function test_vendor_page_sends_without_a_work_order(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'DIRECT-GID', 'internetMessageId' => '<direct@x>', 'conversationId' => 'DIRECT-CID']),
        ]);
        $vendor = $this->assignedVendor(WorkOrder::factory()->create());

        $this->actingAs($this->woc())
            ->post(route('vendor.email.send', $vendor), [
                'vendor_id' => $vendor->id,
                'from_email' => 'coordinator@texasrenters.com',
                'to' => 'vendor-test@example.com',
                'subject' => 'General update',
                'body' => '<p>Hello.</p>',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => null,
            'vendor_id' => $vendor->id,
            'correlation_tag' => 'TX-GEN-'.$vendor->id,
        ]);
    }

    public function test_store_requires_subject_and_body_and_an_assigned_vendor(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $vendor = $this->assignedVendor(WorkOrder::factory()->create());

        $this->actingAs($this->woc())
            ->post(route('work_order.email.send', $workOrder), ['vendor_id' => $vendor->id])
            ->assertSessionHasErrors(['vendor_id', 'subject', 'body']);
    }

    public function test_vendor_users_cannot_read_or_send_work_order_emails(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $vendor = $this->assignedVendor($workOrder);
        $vendorUser = $vendor->user;
        $vendorUser->assignRole('vendor');

        $this->actingAs($vendorUser)
            ->getJson(route('work_order.email.index', $workOrder).'?vendor_id='.$vendor->id)
            ->assertForbidden();

        $this->actingAs($vendorUser)
            ->post(route('work_order.email.send', $workOrder), [
                'vendor_id' => $vendor->id,
                'subject' => 'Unauthorized',
                'body' => '<p>No</p>',
            ])
            ->assertForbidden();
    }

    public function test_authorized_user_can_download_a_stored_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('email-attachments/test/file.pdf', 'PDF contents');
        $message = EmailMessage::factory()->create();
        $attachment = EmailAttachment::query()->create([
            'email_message_id' => $message->id,
            'filename' => 'quote.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
            'path' => 'email-attachments/test/file.pdf',
        ]);

        $this->actingAs($this->woc())
            ->get(route('work_order.email.attachment', $attachment))
            ->assertOk()
            ->assertDownload('quote.pdf');
    }

    private function assignedVendor(WorkOrder $workOrder, string $email = 'abc@example.com'): Vendor
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.fake()->unique()->uuid(),
            'name' => 'ABC',
            'email' => $email,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->vendors()->attach($vendor);

        return $vendor;
    }

    private function woc(): User
    {
        return User::factory()->create()->assignRole('woc');
    }
}
