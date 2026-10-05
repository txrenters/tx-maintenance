<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SyncPortalConversationToChatbot;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Which thread a signed-in party may post to, and which delivery path the
 * message then takes. A portal party's message reaches staff through the
 * chatbot hub and the notification feed, never by SMS — so posting to any
 * other party's thread has no delivery path at all and must be refused
 * rather than answered with "sent".
 */
class ConversationSendPartyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        config(['services.chatbot.enabled' => true]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function user(array $roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * A tenant-role user and a work order the WorkOrderScope lets them see.
     *
     * @return array{0: User, 1: WorkOrder}
     */
    private function tenantWithWorkOrder(): array
    {
        $user = $this->user(['tenant']);
        $tenant = Tenants::factory()->create(['user_id' => $user->id]);

        return [$user, WorkOrder::factory()->create(['tenant_id' => $tenant->id])];
    }

    private function send(User $user, WorkOrder $workOrder, string $type): TestResponse
    {
        return $this->actingAs($user)->post(route('work_order.conversation.send'), [
            'text' => 'Any update on this?',
            'work_order_id' => $workOrder->id,
            'sender_phone_number' => '+15125550000',
            'receiver_phone_number' => '+15125551111',
            'conversation_type' => $type,
        ]);
    }

    public function test_tenant_cannot_post_to_another_partys_thread(): void
    {
        [$user, $workOrder] = $this->tenantWithWorkOrder();

        $this->send($user, $workOrder, 'vendor')->assertForbidden();

        $this->assertDatabaseMissing('work_order_conversations', ['work_order_id' => $workOrder->id]);
        Bus::assertNothingDispatched();
    }

    public function test_tenant_posting_to_their_own_thread_syncs_to_the_chatbot(): void
    {
        [$user, $workOrder] = $this->tenantWithWorkOrder();

        $this->send($user, $workOrder, 'tenant')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]);
        Bus::assertDispatched(SyncPortalConversationToChatbot::class);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }

    /**
     * A hybrid account — an owner record plus a coordinator role — works the
     * work orders, so its outbound messages belong on the texting path. Keyed
     * on the role alone, every one of them was silently dropped.
     */
    public function test_staff_holding_an_owner_role_still_takes_the_texting_path(): void
    {
        $user = $this->user(['owner', 'woc']);
        $workOrder = WorkOrder::factory()->create();

        $this->send($user, $workOrder, 'vendor')->assertSessionHasNoErrors();

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_an_unknown_conversation_type_is_rejected(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->send($this->user(['woc']), $workOrder, 'banana')
            ->assertSessionHasErrors('conversation_type');

        $this->assertDatabaseMissing('work_order_conversations', ['work_order_id' => $workOrder->id]);
        Bus::assertNothingDispatched();
    }

    public function test_every_thread_type_the_ui_posts_is_accepted(): void
    {
        $user = $this->user(['woc']);

        foreach (['tenant', 'owner', 'vendor', 'vendor_tenant', 'vendor_owner'] as $type) {
            $workOrder = WorkOrder::factory()->create();

            $this->send($user, $workOrder, $type)->assertSessionHasNoErrors();

            $this->assertDatabaseHas('work_order_conversations', [
                'work_order_id' => $workOrder->id,
                'conversation_type' => $type,
            ]);
        }
    }
}
