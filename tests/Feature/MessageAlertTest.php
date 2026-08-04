<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The poll behind the new-message chime and desktop popup.
 */
class MessageAlertTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(WorkOrder $workOrder, bool $inbound, array $attributes = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $inbound ? 'The sink is still leaking' : 'A plumber is booked.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? '+15125550000' : '+15125551111',
            'receiver_number' => $inbound ? '+15125551111' : '+15125550000',
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    public function test_the_first_poll_returns_a_cursor_and_no_alerts(): void
    {
        $coordinator = $this->user('woc');
        $workOrder = WorkOrder::factory()->create(['user_id' => $coordinator->id]);
        $message = $this->message($workOrder, inbound: true);

        $response = $this->actingAs($coordinator)->getJson(route('message_alerts'));

        $response->assertOk();
        $response->assertJsonPath('latest_id', $message->id);
        $response->assertJsonCount(0, 'alerts');
    }

    public function test_a_message_newer_than_the_cursor_is_announced(): void
    {
        $coordinator = $this->user('woc');
        $workOrder = WorkOrder::factory()->create([
            'user_id' => $coordinator->id,
            'work_order_no' => '990528',
        ]);

        $seen = $this->message($workOrder, inbound: true);
        $fresh = $this->message($workOrder, inbound: true, attributes: [
            'message' => 'Any update?',
        ]);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $seen->id]));

        $response->assertOk();
        $response->assertJsonCount(1, 'alerts');
        $response->assertJsonPath('alerts.0.id', $fresh->id);
        $response->assertJsonPath('alerts.0.work_order_no', 990528);
        $response->assertJsonPath('alerts.0.party', 'Tenant');
        $response->assertJsonPath('alerts.0.preview', 'Any update?');
        $response->assertJsonPath('new_count', 1);
    }

    public function test_an_alert_names_the_person_who_wrote_in(): void
    {
        $coordinator = $this->user('woc');
        $tenant = Tenants::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Mitchell',
        ]);
        $workOrder = WorkOrder::factory()->create([
            'user_id' => $coordinator->id,
            'tenant_id' => $tenant->id,
        ]);

        $baseline = $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: true, attributes: ['message' => 'Any update?']);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $baseline->id]));

        // A phone number is not recognisable; a name is.
        $response->assertJsonPath('alerts.0.from_name', 'Sarah Mitchell');
    }

    public function test_an_alert_falls_back_to_the_number_when_nobody_matches(): void
    {
        $coordinator = $this->user('woc');
        $workOrder = WorkOrder::factory()->create([
            'user_id' => $coordinator->id,
            'tenant_id' => null,
        ]);

        $baseline = $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: true, attributes: ['message' => 'Any update?']);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $baseline->id]));

        $response->assertJsonPath('alerts.0.from_name', '+15125550000');
    }

    public function test_our_own_outbound_replies_never_alert(): void
    {
        $coordinator = $this->user('woc');
        $workOrder = WorkOrder::factory()->create(['user_id' => $coordinator->id]);

        $inbound = $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: false);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $inbound->id]));

        $response->assertJsonCount(0, 'alerts');
        $response->assertJsonPath('new_count', 0);
    }

    public function test_another_coordinators_work_order_does_not_alert(): void
    {
        $coordinator = $this->user('woc');
        $colleague = $this->user('woc');

        $mine = WorkOrder::factory()->create(['user_id' => $coordinator->id]);
        $baseline = $this->message($mine, inbound: true);

        $theirs = WorkOrder::factory()->create(['user_id' => $colleague->id]);
        $this->message($theirs, inbound: true, attributes: ['message' => 'Not yours']);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $baseline->id]));

        $response->assertJsonCount(0, 'alerts');
    }

    public function test_an_unassigned_work_order_alerts_everyone(): void
    {
        $coordinator = $this->user('woc');

        $mine = WorkOrder::factory()->create(['user_id' => $coordinator->id]);
        $baseline = $this->message($mine, inbound: true);

        // Nobody coordinates this one, so it must not go unheard.
        $orphan = WorkOrder::factory()->create(['user_id' => null]);
        $loose = $this->message($orphan, inbound: true, attributes: ['message' => 'Hello?']);

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $baseline->id]));

        $response->assertJsonCount(1, 'alerts');
        $response->assertJsonPath('alerts.0.id', $loose->id);
    }

    public function test_a_burst_is_capped_but_still_counted(): void
    {
        $coordinator = $this->user('woc');
        $workOrder = WorkOrder::factory()->create(['user_id' => $coordinator->id]);
        $baseline = $this->message($workOrder, inbound: true);

        foreach (range(1, 8) as $index) {
            $this->message($workOrder, inbound: true, attributes: ['message' => "Message {$index}"]);
        }

        $response = $this->actingAs($coordinator)
            ->getJson(route('message_alerts', ['after_id' => $baseline->id]));

        // The browser shows one summary rather than eight popups.
        $response->assertJsonCount(5, 'alerts');
        $response->assertJsonPath('new_count', 8);
    }

    public function test_non_staff_get_nothing(): void
    {
        $vendor = $this->user('vendor');
        $workOrder = WorkOrder::factory()->create(['user_id' => null]);
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($vendor)->getJson(route('message_alerts'));

        $response->assertOk();
        $response->assertJsonPath('latest_id', 0);
        $response->assertJsonCount(0, 'alerts');
    }

    public function test_guests_cannot_poll_for_alerts(): void
    {
        $this->get(route('message_alerts'))->assertRedirect(route('login'));
    }
}
