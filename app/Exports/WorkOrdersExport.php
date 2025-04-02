<?php

namespace App\Exports;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;

class WorkOrdersExport implements FromCollection
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return WorkOrder::with([
                'service_status','requested_by','vendors','managed_by'
            ])
            ->wherehas('service_status',function($q) {
                $q->whereNot('name','Closed')
                ->whereNot('name','Not Changed');
            })
            ->filter(request(['search']))
            ->get()
            ->map(function ($work_order) {
                return [
                    'id' => $work_order->id,
                    'work_order_no' => $work_order->work_order_no,
                    'location' => $work_order->location,
                    'completed_at' => $work_order->completed_date ? Carbon::parse($work_order->completed_date)->format('F d, Y') : null,
                    'requested_by' => $work_order->requested_by?->first_name.' '.$work_order->requested_by?->last_name,
                    'status' => $work_order->service_status->name == 'Closed' ? true : false,
                ];
            });
    }
}
