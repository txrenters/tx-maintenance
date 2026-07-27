<?php

namespace Tests\Feature;

use App\Jobs\ImportWorkOrderJob;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class ImportWorkOrderVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $pwId): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function processVendors(array $data, int $workOrderId): void
    {
        $job = new ImportWorkOrderJob([]);
        $method = new ReflectionMethod($job, 'processVendors');
        $method->setAccessible(true);
        $method->invoke($job, $data, $workOrderId, now()->toDateTimeString());
    }

    public function test_vendor_already_on_another_work_order_is_still_attached_on_import(): void
    {
        $vendor = $this->makeVendor('PW-V-1');

        // The same vendor is already assigned to a different work order.
        $otherWorkOrder = WorkOrder::factory()->create();
        DB::table('work_order_vendors')->insert([
            'work_order_id' => $otherWorkOrder->id,
            'vendor_id' => $vendor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Importing a new work order whose PropertyWare payload lists that vendor.
        $workOrder = WorkOrder::factory()->create();
        $this->processVendors(['vendorIDs' => ['PW-V-1']], $workOrder->id);

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_reimport_does_not_duplicate_the_vendor_on_the_same_work_order(): void
    {
        $vendor = $this->makeVendor('PW-V-2');
        $workOrder = WorkOrder::factory()->create();

        $this->processVendors(['vendorIDs' => ['PW-V-2']], $workOrder->id);
        $this->processVendors(['vendorIDs' => ['PW-V-2']], $workOrder->id);

        $this->assertSame(
            1,
            DB::table('work_order_vendors')
                ->where('work_order_id', $workOrder->id)
                ->where('vendor_id', $vendor->id)
                ->count(),
        );
    }
}
