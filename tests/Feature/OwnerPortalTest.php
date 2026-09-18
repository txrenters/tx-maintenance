<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerPortalLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(string $mobile = '5125551234', string $first = 'Olivia'): Owner
    {
        return Owner::query()->create([
            'first_name' => $first,
            'last_name' => 'Owner',
            'email' => strtolower($first).'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(?Owner $owner = null): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(
            ['name' => 'New'],
            ['description' => 'New'],
        );

        $building = Building::query()->create([
            'propertyware_id' => 7101,
            'name' => '6341 Del Monte Dr',
            'address' => '6341 Del Monte Dr',
            'portfolio_id' => 900,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43900,
            'building_id' => $building->propertyware_id,
            'description' => 'Water heater is leaking in the garage',
        ]);

        if ($owner) {
            $workOrder->owners()->attach($owner->id);
        }

        return $workOrder;
    }

    private function makeToken(WorkOrder $workOrder, Owner $owner): OwnerPortalToken
    {
        return OwnerPortalToken::create([
            'token' => 'demo-owner-token-'.$workOrder->id.'-'.$owner->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_a_bad_token_404s(): void
    {
        $this->get('/owner-portal/not-a-real-token')->assertNotFound();
    }

    public function test_a_valid_token_renders_the_portal(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OwnerPortal/Show')
                ->where('ownerName', 'Olivia')
                ->where('workOrder.work_order_no', 43900)
                ->where('workOrder.address', '6341 Del Monte Dr')
            );
    }

    public function test_the_portal_shows_the_owner_woc_thread_but_never_the_vendor_thread(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        Conversation::create([
            'message' => 'Your appointment is set for Friday.',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551234',
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        Conversation::create([
            'message' => 'Vendor: my crew runs late, quoting $900.',
            'sender_number' => '+15125559999',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'is_mms' => false,
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.message', 'Your appointment is set for Friday.')
                ->where('messages.0.from_owner', false)
            );
    }

    public function test_one_owner_never_sees_a_co_owners_messages(): void
    {
        $owner = $this->makeOwner('5125551234', 'Olivia');
        $coOwner = $this->makeOwner('5125557777', 'Owen');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $token = $this->makeToken($workOrder, $owner);

        Conversation::create([
            'message' => 'Message for Olivia.',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551234',
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        Conversation::create([
            'message' => 'Message for Owen.',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125557777',
            'work_order_id' => $workOrder->id,
            'owner_id' => $coOwner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.message', 'Message for Olivia.')
            );
    }

    public function test_the_owner_can_message_their_coordinator(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/message', [
            'text' => 'Please go ahead with the repair.',
        ])->assertRedirect();

        $message = Conversation::query()->withoutGlobalScopes()->first();

        $this->assertSame('Please go ahead with the repair.', $message->message);
        $this->assertSame('owner', $message->conversation_type);
        $this->assertSame($owner->id, (int) $message->owner_id);
        // Unread for the coordinator, so it surfaces in the owner tab.
        $this->assertFalse((bool) $message->is_read);

        // Messaging counts as responding: the schedule follow-up stops.
        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/message', ['text' => '   '])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_owner_can_upload_photos_which_sync_to_propertyware(): void
    {
        Storage::fake('public');
        Queue::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/attachments', [
            'files' => [UploadedFile::fake()->image('garage.jpg')],
        ])->assertRedirect();

        $attachment = Attachments::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($attachment);
        $this->assertTrue((bool) $attachment->is_publish_to_owner_portal);
        $this->assertSame($workOrder->id, $attachment->work_order_id);

        Queue::assertPushed(UploadAttachment::class);
    }

    public function test_the_portal_only_shows_photos_published_to_the_owner(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        Attachments::create([
            'title' => 'Owner-visible photo',
            'filename' => 'attachments/visible.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $owner->user_id,
            'is_publish_to_owner_portal' => true,
        ]);

        Attachments::create([
            'title' => 'Internal only',
            'filename' => 'attachments/internal.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'user_id' => $owner->user_id,
            'is_publish_to_owner_portal' => false,
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 1)
                ->where('attachments.0.title', 'Owner-visible photo')
            );
    }

    public function test_a_tenant_upload_is_credited_to_the_tenant_not_the_coordinator(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        // The tenant's own photo of the problem, sent in through their portal
        // and published to the owner. It used to read "From work order
        // coordinator", which put the whole gallery under that one heading.
        Attachments::create([
            'title' => 'Tenant photo',
            'filename' => 'attachments/tenant.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => null,
            'uploaded_via_tenant_portal' => true,
            'is_publish_to_owner_portal' => true,
        ]);

        Attachments::create([
            'title' => 'Office photo',
            'filename' => 'attachments/office.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => null,
            'uploaded_via_tenant_portal' => false,
            'is_publish_to_owner_portal' => true,
        ]);

        Attachments::create([
            'title' => 'Owner photo',
            'filename' => 'attachments/owner.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $owner->user_id,
            'is_publish_to_owner_portal' => true,
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 3)
                ->where('attachments', function ($items) {
                    $sources = collect($items)->pluck('source', 'title');

                    return $sources['Tenant photo'] === 'From tenant'
                        && $sources['Office photo'] === 'From work order coordinator'
                        && $sources['Owner photo'] === 'From you';
                })
            );
    }

    public function test_the_gallery_includes_photos_from_the_tenant_and_owner_threads(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'dana@example.com',
            'mobile_phone' => '5125558888',
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->update(['tenant_id' => $tenant->id]);

        $tenantMessage = Conversation::create([
            'message' => 'Here is the leak.',
            'sender_number' => '+15125558888',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
            'is_mms' => true,
        ]);

        ConversationMedia::create([
            'message_id' => $tenantMessage->id,
            'original_url' => '',
            'local_path' => 'conversation_images/leak.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'leak.jpg',
        ]);

        $ownerMessage = Conversation::create([
            'message' => 'Please see the meter.',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551234',
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => true,
        ]);

        ConversationMedia::create([
            'message_id' => $ownerMessage->id,
            'original_url' => '',
            'local_path' => 'conversation_images/meter.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'meter.jpg',
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 2)
                ->where('attachments', fn ($items) => collect($items)
                    ->pluck('source')
                    ->sort()
                    ->values()
                    ->all() === ['From tenant', 'From work order coordinator'])
            );
    }

    public function test_photos_the_owner_sent_are_labelled_as_their_own(): void
    {
        Storage::fake('public');
        Queue::fake();

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        // A photo the owner sent through the portal.
        $this->post('/owner-portal/'.$token->token.'/message', [
            'text' => 'Here is what I saw.',
            'images' => [UploadedFile::fake()->image('mine.jpg')],
        ])->assertRedirect();

        // And one they uploaded on the Photos tab.
        $this->post('/owner-portal/'.$token->token.'/attachments', [
            'files' => [UploadedFile::fake()->image('also-mine.jpg')],
        ])->assertRedirect();

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 2)
                ->where('attachments', fn ($items) => collect($items)
                    ->pluck('source')
                    ->unique()
                    ->values()
                    ->all() === ['From you'])
            );
    }

    public function test_the_gallery_never_includes_vendor_thread_photos(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $vendorMessage = Conversation::create([
            'message' => 'Quote attached.',
            'sender_number' => '+15125559999',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'is_mms' => true,
        ]);

        ConversationMedia::create([
            'message_id' => $vendorMessage->id,
            'original_url' => '',
            'local_path' => 'conversation_images/quote.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'quote.jpg',
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attachments', 0));
    }

    public function test_the_gallery_never_includes_a_co_owners_conversation_photos(): void
    {
        $owner = $this->makeOwner('5125551234', 'Olivia');
        $coOwner = $this->makeOwner('5125557777', 'Owen');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $token = $this->makeToken($workOrder, $owner);

        $coOwnerMessage = Conversation::create([
            'message' => 'For Owen only.',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125557777',
            'work_order_id' => $workOrder->id,
            'owner_id' => $coOwner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => true,
        ]);

        ConversationMedia::create([
            'message_id' => $coOwnerMessage->id,
            'original_url' => '',
            'local_path' => 'conversation_images/owen.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'owen.jpg',
        ]);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attachments', 0));
    }

    public function test_conversation_photos_are_served_through_their_signed_route(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $message = Conversation::create([
            'message' => 'Photo.',
            'sender_number' => '+15125558888',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
            'is_mms' => true,
        ]);

        ConversationMedia::create([
            'message_id' => $message->id,
            'original_url' => '',
            'local_path' => 'conversation_images/leak.jpg',
            'content_type' => 'image/jpeg',
            'file_name' => 'leak.jpg',
        ]);

        // Conversation files live on the private disk, so a public storage URL
        // would 404 for the owner.
        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('attachments.0.url', fn ($url) => str_contains($url, 'conversation-media')
                    && str_contains($url, 'signature='))
            );
    }

    public function test_the_link_service_reuses_one_token_per_owner_per_work_order(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);

        $service = app(OwnerPortalLinkService::class);

        $first = $service->link($workOrder, $owner);
        $second = $service->link($workOrder, $owner);

        $this->assertSame($first, $second);
        $this->assertSame(1, OwnerPortalToken::query()->count());
        $this->assertStringContainsString('/owner-portal/', $first);
    }

    public function test_each_owner_gets_their_own_link(): void
    {
        $owner = $this->makeOwner('5125551234', 'Olivia');
        $coOwner = $this->makeOwner('5125557777', 'Owen');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $service = app(OwnerPortalLinkService::class);

        $this->assertNotSame(
            $service->link($workOrder, $owner),
            $service->link($workOrder, $coOwner),
        );
        $this->assertSame(2, OwnerPortalToken::query()->count());
    }
}
