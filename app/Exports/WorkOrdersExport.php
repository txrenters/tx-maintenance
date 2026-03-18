<?php

namespace App\Exports;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WorkOrdersExport
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        return WorkOrder::with([
            'service_status', 'requested_by', 'vendors', 'managed_by', 'owners',
        ])
            ->whereHas('service_status', function ($q) {
                $q->whereNot('name', 'Closed')
                    ->whereNot('name', 'Not Changed');
            })
            ->where('status', 'Open')
            ->orderBy('created_date', 'DESC')
            ->filter(request(['search', 'vendor', 'start_date', 'end_date']))
            ->get()
            ->map(function ($work_order) {

                $requestedBy = $work_order->requested_by;
                $owner = $work_order->owners->first();

                return [
                    'work_order_no' => $work_order->work_order_no,
                    'location' => $work_order->location,
                    'created_date' => $work_order->created_date ? Carbon::parse($work_order->created_date)->format('F d, Y') : null,
                    'service_status' => trim($work_order->service_status?->name),
                    'description' => trim($work_order->description),
                    'requested_by' => $requestedBy ? "{$requestedBy->first_name} {$requestedBy->last_name}" : ($owner ? "{$owner->first_name} {$owner->last_name}" : null),
                    'managed_by' => $work_order->managed_by?->first_name.' '.$work_order->managed_by?->last_name,
                    'hour_estimate' => $work_order->hour_estimate,
                    'priority' => $work_order->priority,
                    'scheduled_end_date' => $work_order->scheduled_end_date,
                    'source' => $work_order->source,
                    'specific_location' => trim($work_order->specific_location),
                    'total_cost' => $work_order->total_cost,
                    'total_hour_work' => $work_order->total_hour_work,
                    'type' => $work_order->type,
                    'zone' => $work_order->zone,
                    'additional_work_needed_reschedule' => trim($work_order->additional_work_needed_reschedule),
                    'management_plan' => $work_order->management_plan,
                    'closing_comments' => $work_order->closing_comments,
                    'vendors' => implode(', ', $work_order->vendors->pluck('name')->map(fn ($name) => trim($name))->toArray()),
                ];
            });
    }

    public function headings(): array
    {
        return [
            'Work Order',
            'Location',
            'Date Created',
            'Service Status',
            'Description',
            'Requested By',
            'Managed By',
            'Hour Estimate',
            'Priority',
            'Schedule End Date',
            'Source',
            'Specific Location',
            'Total Cost',
            'Total Hour Work',
            'Type',
            'Zone',
            'Additional Work Needed Reschedule',
            'Management Plan',
            'Closing Comments',
            'Vendors',
        ];
    }
}
