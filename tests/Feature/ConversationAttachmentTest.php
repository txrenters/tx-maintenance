<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\ConversationMedia;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ConversationAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function postAttachment(UploadedFile $file): TestResponse
    {
        Bus::fake();
        Storage::fake();

        // A coordinator (no owner/tenant role) so the message takes the outbound
        // texting path rather than the in-app notification path.
        $woc = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        return $this->actingAs($woc)->post(route('work_order.conversation.send'), [
            'text' => 'Here is the reference file.',
            'work_order_id' => $workOrder->id,
            'sender_phone_number' => '+12816999281',
            'receiver_phone_number' => '+15551234567',
            'conversation_type' => 'vendor',
            'images' => [$file],
        ]);
    }

    public function test_woc_can_attach_a_video(): void
    {
        $response = $this->postAttachment(
            UploadedFile::fake()->create('walkthrough.mp4', 2048, 'video/mp4'),
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('work_order_conversation_medias', [
            'file_name' => 'walkthrough.mp4',
            'content_type' => 'video/mp4',
        ]);
        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_woc_can_attach_a_pdf(): void
    {
        $response = $this->postAttachment(
            UploadedFile::fake()->create('scope-of-work.pdf', 1024, 'application/pdf'),
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('work_order_conversation_medias', [
            'file_name' => 'scope-of-work.pdf',
            'content_type' => 'application/pdf',
        ]);
        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_woc_can_still_attach_an_image(): void
    {
        $response = $this->postAttachment(
            UploadedFile::fake()->image('before.jpg'),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, ConversationMedia::count());
    }

    public function test_woc_can_attach_a_3gp_video_even_when_mime_detection_fails(): void
    {
        // Android phone recordings routinely arrive as .3gp files that PHP's
        // fileinfo sniffs as application/octet-stream — the extension must win.
        $response = $this->postAttachment(
            UploadedFile::fake()->create('tenant-video.3gp', 2048, 'application/octet-stream'),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, ConversationMedia::count());
        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_woc_can_attach_any_video_format(): void
    {
        foreach ([
            ['clip.avi', 'video/x-msvideo'],
            ['clip.mkv', 'video/x-matroska'],
            ['clip.wmv', 'video/x-ms-wmv'],
        ] as [$name, $mime]) {
            $this->postAttachment(UploadedFile::fake()->create($name, 512, $mime))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, ConversationMedia::count());
    }

    public function test_work_order_attachment_accepts_a_3gp_video(): void
    {
        Bus::fake();
        Storage::fake('public');

        $woc = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $response = $this->actingAs($woc)->post(route('api.attachments.store'), [
            'title' => 'Tenant video',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'filename' => UploadedFile::fake()->create('leak.3gp', 2048, 'application/octet-stream'),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attachments', [
            'title' => 'Tenant video',
            'work_order_id' => $workOrder->id,
        ]);
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        $response = $this->postAttachment(
            UploadedFile::fake()->create('malware.exe', 16, 'application/octet-stream'),
        );

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, ConversationMedia::count());
    }

    public function test_file_over_the_size_cap_is_rejected(): void
    {
        // 51MB — just past the 50MB (51200KB) per-file cap.
        $response = $this->postAttachment(
            UploadedFile::fake()->create('huge.mp4', 51 * 1024, 'video/mp4'),
        );

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, ConversationMedia::count());
    }
}
