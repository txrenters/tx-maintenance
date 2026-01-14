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

            // If scheduled_end_date exists, use it; otherwise default to 2 hours after start
            if ($schedule->scheduled_end_date) {
                // Use the end date with default time of 5:00 PM (end of work day)
                $endDateTime = Carbon::parse($schedule->scheduled_end_date)->setTime(17, 0);
            } else {
                // Default to 2 hours after start time
                $endDateTime = $startDateTime->copy()->addHours(2);
            }

            return [
                'title' => $schedule->title.' - '.'#'.$schedule->work_order->work_order_no,
                'with' => $tenantInfo,
                'time' => [
                    'start' => $startDateTime->format('Y-m-d H:i'),
                    'end' => $endDateTime->format('Y-m-d H:i'),
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
