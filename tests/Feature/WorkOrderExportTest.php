<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WorkOrderExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_download_work_orders_as_an_excel_file(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'In Progress',
            'description' => 'In progress',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 1001,
            'location' => 'Austin',
            'created_date' => '2026-03-18 09:30:00',
            'description' => 'Leaky faucet',
            'status' => 'Open',
            'source' => 'Portal',
            'specific_location' => 'Kitchen',
            'type' => 'Repair',
            'zone' => 'North',
        ]);

        $response = $this->actingAs($user)->get(route('work_orders.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition');

        $tempFile = tempnam(sys_get_temp_dir(), 'work-orders-export');

        $this->assertNotFalse($tempFile);

        file_put_contents($tempFile, $response->streamedContent());

        $spreadsheet = IOFactory::load($tempFile);

        try {
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Work Order', $sheet->getCell('A1')->getValue());
            $this->assertSame(1001, $sheet->getCell('A2')->getValue());
            $this->assertSame('Austin', $sheet->getCell('B2')->getValue());
            $this->assertSame('Leaky faucet', $sheet->getCell('E2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($tempFile);
        }
    }
}
