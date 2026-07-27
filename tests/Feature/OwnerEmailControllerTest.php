<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerEmailAttachment;
use App\Models\OwnerEmailNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerWorkOrderEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('owner', 'web');
    }

    public function test_owner_index_filters_to_an_actual_owned_work_order(): void
    {
        $owner = Owner::factory()->create();
        $workOrder = $this->ownedWorkOrder($owner);
        $otherWorkOrder = $this->ownedWorkOrder($owner);
        OwnerEmailNotification::factory()->create([
            'owner_id' => $owner->id,
            'work_order_id' => $workOrder->id,
            'subject' => 'Requested thread',
        ]);
        OwnerEmailNotification::factory()->create([
            'owner_id' => $owner->id,
            'work_order_id' => $otherWorkOrder->id,
            'subject' => 'Other thread',
        ]);

        $response = $this->actingAs($this->woc())
            ->getJson(route('owner.email.index', $owner).'?work_order_id='.$workOrder->id);

        $response->assertOk();
        $this->assertSame(['Requested thread'], collect($response->json('owner_emails'))->pluck('subject')->all());
    }

    public function test_work_order_index_filters_to_an_actual_owner(): void
    {
        $owner = Owner::factory()->create();
        $otherOwner = Owner::factory()->create();
        $workOrder = $this->ownedWorkOrder($owner);
        $workOrder->owners()->attach($otherOwner);
        OwnerEmailNotification::factory()->create([
            'owner_id' => $owner->id,
            'work_order_id' => $workOrder->id,
            'subject' => 'Selected owner',
        ]);
        OwnerEmailNotification::factory()->create([
            'owner_id' => $otherOwner->id,
            'work_order_id' => $workOrder->id,
            'subject' => 'Other owner',
        ]);

        $response = $this->actingAs($this->woc())
            ->getJson(route('work_order.owner_email.index', $workOrder).'?owner_id='.$owner->id);

        $response->assertOk();
        $this->assertSame(['Selected owner'], collect($response->json('owner_emails'))->pluck('subject')->all());
    }

    public function test_store_sends_for_a_property_owner_without_a_work_order(): void
    {
        $owner = Owner::factory()->create();
        $user = $this->woc();
        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(fn ($sentOwner, $workOrder): bool => $sentOwner->is($owner) && $workOrder === null);
        $this->app->instance(OwnerWorkOrderEmailSender::class, $sender);

        $this->actingAs($user)
            ->post(route('owner.email.send', $owner), [
                'to' => 'owner@example.com',
                'from_email' => $user->email,
                'subject' => 'Repair update',
                'body' => '<p>The repair is scheduled.</p>',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_work_order_store_sends_for_an_actual_property_owner(): void
    {
        $owner = Owner::factory()->create();
        $workOrder = $this->ownedWorkOrder($owner);
        $user = $this->woc();
        $sender = Mockery::mock(OwnerWorkOrderEmailSender::class);
        $sender->shouldReceive('send')->once();
        $this->app->instance(OwnerWorkOrderEmailSender::class, $sender);

        $this->actingAs($user)
            ->post(route('work_order.owner_email.send', $workOrder), [
                'owner_id' => $owner->id,
                'to' => 'owner@example.com',
                'from_email' => $user->email,
                'subject' => 'Repair update',
                'body' => '<p>The repair is scheduled.</p>',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_management_contact_relationship_does_not_authorize_owner_email(): void
    {
        $owner = Owner::factory()->create();
        $workOrder = WorkOrder::factory()->create(['property_manager_id' => $owner->id]);

        $this->actingAs($this->woc())
            ->post(route('owner.email.send', $owner), [
                'work_order_id' => $workOrder->id,
                'to' => 'owner@example.com',
                'subject' => 'Invalid',
                'body' => '<p>No</p>',
            ])
            ->assertSessionHasErrors('work_order_id');
    }

    public function test_owner_role_cannot_read_send_or_download_owner_emails(): void
    {
        Storage::fake('local');
        $owner = Owner::factory()->create();
        $workOrder = $this->ownedWorkOrder($owner);
        $message = OwnerEmailNotification::factory()->create([
            'owner_id' => $owner->id,
            'work_order_id' => $workOrder->id,
        ]);
        $attachment = OwnerEmailAttachment::query()->create([
            'owner_email_notification_id' => $message->id,
            'filename' => 'estimate.pdf',
            'mime' => 'application/pdf',
            'size' => 3,
            'path' => 'owner-email-attachments/file.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf');
        $ownerUser = User::factory()->create()->assignRole('owner');

        $this->actingAs($ownerUser)
            ->getJson(route('owner.email.index', $owner))
            ->assertForbidden();
        $this->actingAs($ownerUser)
            ->post(route('owner.email.send', $owner), [
                'work_order_id' => $workOrder->id,
                'to' => 'owner@example.com',
                'subject' => 'No',
                'body' => '<p>No</p>',
            ])
            ->assertForbidden();
        $this->actingAs($ownerUser)
            ->get(route('owner.email.attachment', $attachment))
            ->assertForbidden();
    }

    public function test_staff_can_download_existing_attachment_and_missing_file_is_not_found(): void
    {
        Storage::fake('local');
        $message = OwnerEmailNotification::factory()->create();
        $attachment = OwnerEmailAttachment::query()->create([
            'owner_email_notification_id' => $message->id,
            'filename' => 'estimate.pdf',
            'mime' => 'application/pdf',
            'size' => 3,
            'path' => 'owner-email-attachments/file.pdf',
        ]);

        $this->actingAs($this->woc())
            ->get(route('owner.email.attachment', $attachment))
            ->assertNotFound();

        Storage::disk('local')->put($attachment->path, 'pdf');

        $this->actingAs($this->woc())
            ->get(route('owner.email.attachment', $attachment))
            ->assertOk()
            ->assertDownload('estimate.pdf');
    }

    private function ownedWorkOrder(Owner $owner): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create();
        $workOrder->owners()->attach($owner);

        return $workOrder;
    }

    private function woc(): User
    {
        return User::factory()->create()->assignRole('woc');
    }
}
