<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InboundTwilioMessageProcessor;
use App\Services\TenantPhotoMirrorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Photos a tenant sends through their conversation thread (MMS reply or
 * portal chat) must land on the staff Attachments tab as Before Pictures,
 * and new arrivals must drive the tab's number badge until staff open it.
 */
class TenantAttachmentMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

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

    private function makeWorkOrder(Tenants $tenant): WorkOrder
    {
        return WorkOrder::factory()->create([
            'work_order_no' => 43361,
            'tenant_id' => $tenant->id,
            'description' => 'Water dripping from the roof',
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

    public function test_inbound_tenant_mms_photos_are_mirrored_into_attachments(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        Http::fake(['*' => Http::response('fake-image-bytes')]);

        $workOrder = $this->makeWorkOrder($this->makeTenant());

        $result = app(InboundTwilioMessageProcessor::class)->process([
            'From' => '+15125559999',
            'To' => '+15125550000',
            'Body' => 'Here are the pictures (Ref: WO#43361)',
            'MessageSid' => 'SM-mirror-test-1',
            'NumMedia' => '1',
            'MediaUrl0' => 'https://api.twilio.com/media/photo-1',
            'MediaContentType0' => 'image/jpeg',
        ]);

        $this->assertSame('work_order', $result);

        $attachment = Attachments::query()->sole();
        $this->assertSame($workOrder->id, (int) $attachment->work_order_id);
        $this->assertSame('before', $attachment->type);
        $this->assertSame('image/jpeg', $attachment->filetype);
        $this->assertNull($attachment->viewed_by_staff_at);
        // The tenant portal gallery already renders the conversation photo —
        // the mirror must stay out of it or every picture shows twice.
        $this->assertFalse((bool) $attachment->is_publish_to_tenant_portal);
        $this->assertFalse((bool) $attachment->uploaded_via_tenant_portal);

        Storage::disk('public')->assertExists($attachment->filename);
        $this->assertSame('fake-image-bytes', Storage::disk('public')->get($attachment->filename));

        Queue::assertPushed(UploadAttachment::class, 1);
    }

    public function test_non_visual_mms_media_is_not_mirrored(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        Http::fake(['*' => Http::response('voice-note-bytes')]);

        $this->makeWorkOrder($this->makeTenant());

        app(InboundTwilioMessageProcessor::class)->process([
            'From' => '+15125559999',
            'To' => '+15125550000',
            'Body' => 'Voice note (Ref: WO#43361)',
            'MessageSid' => 'SM-mirror-test-2',
            'NumMedia' => '1',
            'MediaUrl0' => 'https://api.twilio.com/media/audio-1',
            'MediaContentType0' => 'audio/mpeg',
        ]);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_portal_chat_photos_are_mirrored_into_attachments(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.message', $token->token), [
            'text' => 'I fixed the edging, see the photo',
            'images' => [UploadedFile::fake()->image('fix.jpg')],
        ])->assertRedirect();

        $this->assertSame(1, ConversationMedia::query()->count());

        $attachment = Attachments::query()->sole();
        $this->assertSame($workOrder->id, (int) $attachment->work_order_id);
        $this->assertSame('before', $attachment->type);
        $this->assertNull($attachment->viewed_by_staff_at);
        Storage::disk('public')->assertExists($attachment->filename);
    }

    public function test_owner_thread_media_is_never_mirrored(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $workOrder = $this->makeWorkOrder($this->makeTenant());

        $conversation = Conversation::create([
            'message' => 'photo from the owner',
            'conversation_type' => 'owner',
            'sender_number' => '+15125551234',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
        ]);

        Storage::disk('local')->put('message_media/1/owner.jpg', 'owner-bytes');

        $media = ConversationMedia::create([
            'message_id' => $conversation->id,
            'original_url' => '',
            'local_path' => 'message_media/1/owner.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'owner.jpg',
        ]);

        $created = app(TenantPhotoMirrorService::class)->mirrorForConversation($conversation, [$media]);

        $this->assertSame([], $created);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_mirror_survives_a_work_order_without_a_linked_tenant(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');

        // No requester on the work order — the attachment can't be attributed
        // to a login, which used to violate the NOT NULL user_id column.
        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 43362,
            'tenant_id' => null,
        ]);

        $conversation = Conversation::create([
            'message' => 'photo',
            'conversation_type' => 'tenant',
            'sender_number' => '+15125559999',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
        ]);

        Storage::disk('local')->put('message_media/1/photo.jpg', 'photo-bytes');

        $media = ConversationMedia::create([
            'message_id' => $conversation->id,
            'original_url' => '',
            'local_path' => 'message_media/1/photo.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'photo.jpg',
        ]);

        app(TenantPhotoMirrorService::class)->mirrorForConversation($conversation, [$media]);

        $attachment = Attachments::query()->sole();
        $this->assertNull($attachment->user_id);
        $this->assertSame($workOrder->id, (int) $attachment->work_order_id);
    }

    public function test_opening_the_attachments_tab_clears_the_new_attachment_badge(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $workOrder = $this->makeWorkOrder($this->makeTenant());

        Attachments::create([
            'title' => 'Tenant photo - WO#43361',
            'filename' => 'attachments/example.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => null,
        ]);

        // The modal payload carries the badge count...
        $this->actingAs($admin)
            ->getJson(route('work_orders.data', $workOrder))
            ->assertOk()
            ->assertJsonPath('unseen_attachments_count', 1);

        // ...opening the Attachments tab marks everything viewed...
        $this->actingAs($admin)
            ->getJson(route('api.attachments.show', $workOrder))
            ->assertOk();

        $this->assertNotNull(Attachments::query()->sole()->viewed_by_staff_at);

        // ...and the badge is gone on the next open.
        $this->actingAs($admin)
            ->getJson(route('work_orders.data', $workOrder))
            ->assertOk()
            ->assertJsonPath('unseen_attachments_count', 0);
    }

    public function test_a_staff_members_own_upload_never_badges(): void
    {
        Queue::fake();
        Storage::fake('public');

        $admin = User::factory()->create()->assignRole('admin');
        $workOrder = $this->makeWorkOrder($this->makeTenant());

        $this->actingAs($admin)->post(route('api.attachments.multiple_store'), [
            'title' => 'Site visit photos',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'tenant_portal' => 'No',
            'owner_portal' => 'No',
            'files' => [
                [
                    'file' => UploadedFile::fake()->image('after.jpg'),
                    'name' => 'after.jpg',
                    'type' => 'image/jpeg',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(Attachments::query()->sole()->viewed_by_staff_at);
    }
}
