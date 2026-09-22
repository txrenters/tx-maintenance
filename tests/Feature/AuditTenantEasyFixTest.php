<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditTenantEasyFixTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(array $attributes = []): WorkOrder
    {
        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'source' => 'Tenant Portal',
            'propertyware_id' => random_int(1000000, 9999999),
            'lease_id' => 555001,
            'created_date' => now()->subDays(3),
        ], $attributes));
    }

    public function test_it_lists_the_easy_fixes_and_tenant_owned_appliances_without_writing_anything(): void
    {
        Storage::fake('local');

        $building = Building::query()->create([
            'propertyware_id' => 'B-700OAK',
            'name' => 'Oak',
            'address' => '700 Oak St',
            'city' => 'Houston',
            'state_region' => 'TX',
            'custom_fields' => [['fieldName' => 'Included Appliances', 'value' => 'refrigerator', 'dataType' => 'Text']],
        ]);

        $disposal = $this->makeWorkOrder(['work_order_no' => 44001, 'description' => 'Garbage disposal is humming but not turning', 'category' => 'Garbage Disposal']);
        $washer = $this->makeWorkOrder(['work_order_no' => 44002, 'description' => 'Our washing machine will not spin', 'category' => 'Washer', 'building_id' => $building->propertyware_id]);
        $heater = $this->makeWorkOrder(['work_order_no' => 44003, 'description' => 'Water heater is leaking in the garage', 'category' => 'Plumbing']);
        $old = $this->makeWorkOrder(['work_order_no' => 44004, 'description' => 'Garbage disposal jammed', 'category' => 'Garbage Disposal', 'created_date' => now()->subDays(200)]);

        $this->artisan('easy-fix:audit', ['--days' => 30, '--csv' => 'easy-fix-audit.csv'])
            ->expectsOutputToContain('Would be texted the easy-fix how-to')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('easy-fix-audit.csv');
        $csv = Storage::disk('local')->get('easy-fix-audit.csv');

        $this->assertStringContainsString('44001,', $csv);
        $this->assertStringContainsString('easy_fix,disposal_jammed', $csv);
        $this->assertStringContainsString('44002,', $csv);
        $this->assertStringContainsString('appliance,appliance_washer', $csv);
        $this->assertStringNotContainsString('44003,', $csv);
        $this->assertStringNotContainsString('44004,', $csv);

        // Read-only: no verdict recorded on any work order.
        $this->assertSame(0, DB::table('work_orders')->whereNotNull('easy_fix_assessed_at')->count());
    }

    public function test_all_lists_every_scanned_work_order(): void
    {
        Storage::fake('local');

        $this->makeWorkOrder(['work_order_no' => 44003, 'description' => 'Water heater is leaking in the garage', 'category' => 'Plumbing']);

        $this->artisan('easy-fix:audit', ['--days' => 30, '--csv' => 'all.csv', '--all' => true])->assertSuccessful();

        $this->assertStringContainsString('44003,', Storage::disk('local')->get('all.csv'));
    }
}
