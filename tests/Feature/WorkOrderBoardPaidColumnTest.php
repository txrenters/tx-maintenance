<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every board rebuilds Paid as a trailing 30-day bucket. The Paid status row
 * itself must not also stay in the regular columns, or the board shows two
 * PAID columns.
 */
class WorkOrderBoardPaidColumnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function boards(): array
    {
        return [
            'active' => ['work_orders.index', 'WorkOrder/Index'],
            'inspections' => ['work_orders.inspections', 'WorkOrder/Inspections'],
            'hvac' => ['work_orders.hvac', 'WorkOrder/Hvac'],
            'easy fix' => ['work_orders.easy_fix', 'WorkOrder/EasyFix'],
        ];
    }

    #[DataProvider('boards')]
    public function test_the_board_has_exactly_one_paid_column_and_it_comes_before_closed(string $route, string $component): void
    {
        foreach (['In Progress', 'Paid', 'Completed - Verified - Waiting on Bill', 'Approved - Waiting on Payment', 'Closed'] as $name) {
            ServiceStatus::query()->create(['name' => $name, 'description' => $name]);
        }

        Role::findOrCreate('woc', 'web');
        $staff = tap(User::factory()->create())->assignRole('woc');

        $response = $this->actingAs($staff)->get(route($route), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $names = collect($response->json('props.service_status'))->pluck('name')->values()->all();

        $this->assertSame(1, collect($names)->filter(fn (string $name) => $name === 'Paid')->count(), 'Columns: '.implode(', ', $names));
        $this->assertSame(['Paid', 'Closed'], array_slice($names, -2));
    }
}
