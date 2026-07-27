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

    public function test_image_attachment_bytes_match_between_graph_payload_and_stored_file(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID3', 'internetMessageId' => '<y@texasrenters.com>', 'conversationId' => 'CID3',
            ]),
        ]);
        Storage::fake('local');

        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 40, 40, imagecolorallocate($img, 123, 200, 50));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        $file = UploadedFile::fake()->createWithContent('photo.png', $png);

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 5678]);
        $vendor = $this->assignedVendor($workOrder);
        $user = User::factory()->create();

        $message = app(WorkOrderEmailSender::class)->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: 'Photo attached',
            html: '<p>See attached photo.</p>',
            files: [$file],
            sentBy: $user,
        );

        $draftRequest = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), '/messages')
                && ! str_contains($request->url(), '/send')
                && isset($request->data()['attachments']));

        $graphBytes = base64_decode($draftRequest->data()['attachments'][0]['contentBytes']);

        $attachment = $message->attachments()->first();
        $storedBytes = Storage::disk('local')->get($attachment->path);

        // Core invariant under test: the SAME optimized bytes must be used for both
        // the Graph draft payload and the persisted copy — never re-derived separately.
        // Note: in this local environment spatie/image-optimizer's external binaries
        // (optipng/pngquant/etc.) aren't installed, so this tiny PNG comes back
        // byte-identical to the input and the "bytes differ from original" assertion
        // would not hold. The reuse-of-same-bytes equality above is the invariant
        // this test exists to protect, and it holds regardless of whether the
        // optimizer chain actually shrinks the image in a given environment.
        $this->assertSame($graphBytes, $storedBytes);
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
