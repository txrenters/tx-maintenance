<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use Carbon\Carbon;

class CalendarController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $service_schedules = ServiceSchedule::with(['work_order', 'tenant', 'vendor'])->get();

        $events = $service_schedules->map(function ($schedule) {
            $tenantInfo = $schedule->tenant
                ? 'Tenant: '.$schedule->tenant->first_name.' '.$schedule->tenant->last_name
                : 'No tenant assigned';

            // Since scheduled_date is now a date only, set default time to 9:00 AM
            $startDateTime = Carbon::parse($schedule->scheduled_date)->setTime(9, 0);

            return [
                'title' => $schedule->title.' - '.'#'.$schedule->work_order->work_order_no,
                'with' => $tenantInfo,
                'time' => [
                    'start' => $startDateTime->format('Y-m-d H:i'),
                    'end' => $startDateTime->copy()->addHours(2)->format('Y-m-d H:i'),
                ],
                'id' => $schedule->id,
                'description' => 'Vendor: '.$schedule->vendor->name.($schedule->description ? ' - '.$schedule->description : ''),
            ];
        });

        return inertia('Calendar/Index', [
            'title' => 'Scheduled Service',
            'service_schedules' => $events,
        ]);
    }
}
