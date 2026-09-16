<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The WOC's manual "send intake notification" button.
 *
 * The shape under test is WO#44160: a PropertyWare website request imported
 * before its lease was attached, so hasNoLeaseOnFile() reads the occupied home
 * as vacant and the one-shot intake messages were muted with a real tenant on
 * the work order.
 */
class IntakeNotificationButtonTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Joseph',
            'last_name' => 'Baty',
            'email' => 'joseph@example.com',
            'mobile_phone' => '2818656037',
            'address' => '123 Oak Ridge Dr',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A website request imported without its lease: PropertyWare id set,
     * lease_id null, a tenant on the work order but no lease roster.
     */
    private function mutedByMissingLease(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'propertyware_id' => '8231845977',
            'lease_id' => null,
            'source' => 'Website',
            'status' => 'Open',
            'tenant_id' => $this->tenant()->id,
        ]);
    }

    public function test_a_muted_work_order_asks_for_confirmation_before_sending(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'tenant']))
            ->assertOk()
            ->assertJson([
                'needs_confirmation' => true,
                'reason' => 'no_lease_on_file',
            ]);

        Queue::assertNothingPushed();
    }

    /**
     * The regression this button exists for: confirming must actually reach the
     * tenant. Clearing the one-shot stamp alone is not enough, because the
     * sender re-checks the same mute and would return silently.
     */
    public function test_confirming_queues_the_send_with_force(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'tenant']), ['confirm' => true])
            ->assertOk()
            ->assertJson(['queued' => true, 'forced' => true]);

        Queue::assertPushed(
            SendTenantServiceRequestNotificationJob::class,
            fn (SendTenantServiceRequestNotificationJob $job): bool => $job->workOrderId === $workOrder->id && $job->force === true,
        );

        Queue::assertPushed(
            SendTenantWorkOrderIntakeEmailJob::class,
            fn (SendTenantWorkOrderIntakeEmailJob $job): bool => $job->force === true,
        );
    }

    public function test_the_one_shot_stamp_is_cleared_so_the_sender_runs_again(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();
        $workOrder->forceFill(['tenant_service_request_notified_at' => now()])->save();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'tenant']), ['confirm' => true])
            ->assertOk();

        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
    }

    public function test_an_unblocked_work_order_sends_without_confirmation_or_force(): void
    {
        Queue::fake();

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => '8231845977',
            'lease_id' => '99001',
            'source' => 'Website',
            'status' => 'Open',
            'tenant_id' => $this->tenant()->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'tenant']))
            ->assertOk()
            ->assertJson(['queued' => true, 'forced' => false]);

        Queue::assertPushed(
            SendTenantServiceRequestNotificationJob::class,
            fn (SendTenantServiceRequestNotificationJob $job): bool => $job->force === false,
        );
    }

    public function test_the_owner_button_queues_only_the_owner_send(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'owner']), ['confirm' => true])
            ->assertOk()
            ->assertJson(['queued' => true, 'audience' => 'owner']);

        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class);
        Queue::assertNotPushed(SendTenantServiceRequestNotificationJob::class);
    }

    public function test_paused_automation_refuses_the_send(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();
        $workOrder->setAutomationPaused('tenant', true);
        $workOrder->save();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'tenant']), ['confirm' => true])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_audience_is_rejected(): void
    {
        Queue::fake();

        $workOrder = $this->mutedByMissingLease();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.notify_intake', [$workOrder, 'vendor']), ['confirm' => true])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
