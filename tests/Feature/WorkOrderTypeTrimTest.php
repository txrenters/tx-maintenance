<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderTypeTrimTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_is_trimmed_on_write(): void
    {
        $workOrder = WorkOrder::factory()->create(['type' => 'Turnover ']);

        $this->assertSame('Turnover', $workOrder->fresh()->type);

        $workOrder->update(['type' => '  Service Request  ']);

        $this->assertSame('Service Request', $workOrder->fresh()->type);
    }

    public function test_null_type_stays_null(): void
    {
        $workOrder = WorkOrder::factory()->create(['type' => null]);

        $this->assertNull($workOrder->fresh()->type);
    }

    public function test_is_turnover_matches_untrimmed_legacy_values(): void
    {
        // Rows written before the mutator existed may still carry whitespace
        // (bypassing Eloquent). isTurnover() must match them regardless.
        $workOrder = WorkOrder::factory()->create();
        DB::table('work_orders')->where('id', $workOrder->id)->update(['type' => 'Turnover ']);

        $this->assertTrue($workOrder->fresh()->isTurnover());
    }
}
