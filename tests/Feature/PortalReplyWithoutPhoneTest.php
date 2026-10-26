<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An owner or tenant with no phone on file writes in from the portal. The
 * coordinator's reply is saved to their portal thread instead of texted.
 */
class PortalReplyWithoutPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function coordinator(): User
    {
        Role::findOrCreate('woc', 'web');

        return tap(User::factory()->create())->assignRole('woc');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reply(WorkOrder $workOrder, string $type, array $extra = []): TestResponse
    {
        return $this->actingAs($this->coordinator())->post(route('work_order.conversation.send'), [
            'text' => 'We will be out Tuesday.',
            'work_order_id' => $workOrder->id,
            'sender_phone_number' => '+15125550000',
            'receiver_phone_number' => '',
            'conversation_type' => $type,
        ] + $extra);
    }

    public function test_owner_without_a_phone_gets_the_reply_in_their_portal(): void
    {
        Bus::fake();
        $workOrder = WorkOrder::factory()->create();
        $owner = Owner::factory()->create(['phone' => null, 'mobile' => null]);
        $workOrder->owners()->attach($owner);

        $this->reply($workOrder, 'owner', ['owner_id' => $owner->id])->assertSessionHasNoErrors();

        $conversation = Conversation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)->sole();
        $this->assertSame($owner->id, $conversation->owner_id);
        $this->assertNull($conversation->receiver_number);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }

    public function test_tenant_without_a_phone_gets_the_reply_in_their_portal(): void
    {
        Bus::fake();
        $tenant = Tenants::factory()->create(['mobile_phone' => null, 'home_phone' => null]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id]);

        $this->reply($workOrder, 'tenant', ['tenant_id' => $tenant->id])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_conversations', ['work_order_id' => $workOrder->id, 'conversation_type' => 'tenant', 'receiver_number' => null]);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }

    public function test_a_reply_with_no_phone_and_nobody_named_is_refused_and_not_saved(): void
    {
        Bus::fake();
        $workOrder = WorkOrder::factory()->create();

        $this->reply($workOrder, 'owner')->assertSessionHasErrors('message');

        $this->assertDatabaseMissing('work_order_conversations', ['work_order_id' => $workOrder->id]);
        Bus::assertNothingDispatched();
    }

    public function test_an_owner_who_is_not_on_the_work_order_is_refused(): void
    {
        Bus::fake();
        $workOrder = WorkOrder::factory()->create();
        $stranger = Owner::factory()->create();

        $this->reply($workOrder, 'owner', ['owner_id' => $stranger->id])->assertSessionHasErrors('message');

        $this->assertDatabaseMissing('work_order_conversations', ['work_order_id' => $workOrder->id]);
        Bus::assertNothingDispatched();
    }
}
