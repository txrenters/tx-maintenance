<?php

namespace Tests\Feature;

use App\Ai\Agents\StaleWorkOrderAgent;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The per-row AI digest on the open-over-30 report: why a stale work order
 * looks stuck and the next step. On demand, cached a day, and unavailable
 * (never broken) when AI is off or not configured.
 */
class StaleWorkOrderDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function aiReady(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
    }

    public function test_analyze_returns_a_digest_and_caches_it(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create(['description' => 'Broken fence']);

        StaleWorkOrderAgent::fake([[
            'stuck_reason' => 'The vendor never scheduled a visit.',
            'next_action' => 'Call Acme Fencing to set a date.',
        ]]);

        $this->actingAs($this->staff())
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('stuck_reason', 'The vendor never scheduled a visit.')
            ->assertJsonPath('next_action', 'Call Acme Fencing to set a date.');

        $this->assertTrue(Cache::has('stale_digest.'.$workOrder->id));

        // A repeat click serves the cache — same digest, no fresh generation.
        $this->actingAs($this->staff())
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id])
            ->assertOk()
            ->assertJsonPath('stuck_reason', 'The vendor never scheduled a visit.');

        // refresh=true regenerates.
        StaleWorkOrderAgent::fake([[
            'stuck_reason' => 'Parts are on order since last week.',
            'next_action' => 'Check the supplier ETA.',
        ]]);

        $this->actingAs($this->staff())
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id, 'refresh' => true])
            ->assertOk()
            ->assertJsonPath('stuck_reason', 'Parts are on order since last week.');
    }

    public function test_gating_and_unavailability(): void
    {
        $workOrder = WorkOrder::factory()->create();

        // Reports are staff-only.
        $this->actingAs($this->staff('accounting'))
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id])
            ->assertForbidden();

        // AI keys blank (phpunit.xml): unavailable, nothing cached.
        $this->actingAs($this->staff())
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id])
            ->assertOk()
            ->assertJsonPath('available', false);
        $this->assertFalse(Cache::has('stale_digest.'.$workOrder->id));

        // Feature gate off: unavailable even with a key.
        $this->aiReady();
        config(['services.ai.stale_digest' => false]);
        $this->actingAs($this->staff())
            ->postJson(route('reports.open_over_30_days.analyze'), ['work_order_id' => $workOrder->id])
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_the_report_page_carries_the_digest_flag(): void
    {
        $this->actingAs($this->staff())
            ->get(route('reports.open_over_30_days'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Kpi')
                ->where('aiDigest', true));

        config(['services.ai.stale_digest' => false]);

        $this->actingAs($this->staff())
            ->get(route('reports.open_over_30_days'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('aiDigest', false));
    }
}
