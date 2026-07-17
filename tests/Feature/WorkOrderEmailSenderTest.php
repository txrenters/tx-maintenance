<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\WorkOrderEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderEmailSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_has_email_messages_relation(): void
    {
        $email = EmailMessage::factory()->create();

        $workOrder = WorkOrder::find($email->work_order_id);

        $this->assertTrue($workOrder->emailMessages()->whereKey($email->id)->exists());
        $this->assertSame(['mc@texasrenters.com', 'ofm@txhomemp.com'], $email->cc);
    }

    private function assignedVendor(WorkOrder $workOrder): Vendor
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'ABC Plumbing',
            'email' => 'abc@example.com',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        return $vendor;
    }

    public function test_send_vendor_email_tags_subject_persists_and_stores_attachment(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID', 'internetMessageId' => '<x@texasrenters.com>', 'conversationId' => 'CID',
            ]),
        ]);
        Storage::fake('local');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 1234]);
        $vendor = $this->assignedVendor($workOrder);
        $user = User::factory()->create();

        $message = app(WorkOrderEmailSender::class)->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: 'Quick question',
            html: '<p>Hello <script>bad()</script></p>',
            files: [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')],
            sentBy: $user,
        );

        $this->assertStringContainsString('[TX-1234-'.$vendor->id.']', $message->subject);
        $this->assertStringNotContainsString('bad()', $message->body_html);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame('GID', $message->graph_message_id);
        $this->assertSame($user->id, $message->sent_by_user_id);
        $this->assertDatabaseHas('email_attachments', [
            'email_message_id' => $message->id,
            'filename' => 'quote.pdf',
        ]);
        $this->assertTrue($message->has_attachments);
    }

    public function test_trusted_html_is_not_sanitized(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'GID2']),
        ]);

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 22]);
        $vendor = $this->assignedVendor($workOrder);

        $message = app(WorkOrderEmailSender::class)->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: 'Service Request',
            html: '<table><tr><td>Styled template</td></tr></table>',
            trustedHtml: true,
        );

        $this->assertStringContainsString('<table>', $message->body_html);
    }
}
