<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;

class SyncWorkOrderDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $data;
    public int $work_order_id;

    /**
     * Create a new job instance.
     */
    public function __construct(array $data, int $work_order_id)
    {
        $this->data = $data;
        $this->work_order_id = $work_order_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = now();

        // Prepare the work order data
        $workOrderData = [
            'approval_comments' => $this->data['approvalComments'] ?? null,
            'is_approved' => $this->data['approved'] ?? false,
            'approved_by' => $this->data['approvedBy'] ?? null,
            'approved_date' => $this->parseDate($this->data['approvedDate'] ?? null),
            'authorized_to_enter' => $this->data['authorizedToEnter'] ?? null,
            'category' => $this->data['category'] ?? null,
            'closing_comments' => $this->data['closingComments'] ?? '',
            'completed_date' => $this->parseDate($this->data['completedDate'] ?? null),
            'cost_estimate' => $this->data['costEstimate'] ?? null,
            'created_date' => $this->parseDate($this->data['createdDate'] ?? null),
            'date_to_enter' => $this->parseDate($this->data['dateToEnter'] ?? null),
            'description' => $this->data['description'] ?? null,
            'hour_estimate' => $this->data['hourEstimate'] ?? null,
            'location' => $this->data['location'] ?? null,
            'priority' => $this->data['priority'] ?? false,
            'priority_as_int' => $this->data['priorityAsInt'] ?? null,
            'required_materials' => $this->data['requiredMaterials'] ?? null,
            'scheduled_end_date' => $this->parseDate($this->data['scheduledEndDate'] ?? null),
            'service_request_building' => $this->data['serviceRequestBuilding'] ?? null,
            'service_request_company_name' => $this->data['serviceRequestCompanyName'] ?? null,
            'service_request_contact_email' => $this->data['serviceRequestContactEmail'] ?? null,
            'service_request_contact_name' => $this->data['serviceRequestContactName'] ?? null,
            'service_request_contact_phone' => $this->data['serviceRequestContactPhone'] ?? null,
            'service_request_contact_phone_type' => $this->data['serviceRequestContactPhoneType'] ?? null,
            'service_request_unit' => $this->data['serviceRequestUnit'] ?? null,
            'source' => $this->data['source'] ?? null,
            'specific_location' => $this->data['specificLocation'] ?? null,
            'start_date' => $this->parseDate($this->data['startDate'] ?? null),
            'status' => $this->data['status'] ?? null,
            'total_cost' => $this->data['totalCost'] ?? null,
            'total_hour_work' => $this->data['totalHourWork'] ?? null,
            'type' => $this->data['type'] ?? null,
            'updated_at' => $now,
        ];

        Log::info('Updating Work Order', [
            'work_order_id' => $this->work_order_id,
            'data' => $workOrderData,
        ]);

        // Find the work order
        $workOrder = WorkOrder::find($this->work_order_id);

        if (!$workOrder) {
            Log::error('Work Order not found', ['work_order_id' => $this->work_order_id]);
            return;
        }

        // Update the work order
        $workOrder->update($workOrderData);

        Log::info('Work Order updated successfully', ['work_order_id' => $this->work_order_id]);
    }

    /**
     * Parse a date string into a Carbon instance or return null if empty.
     */
    private function parseDate(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->toDateString() : null;
    }
}