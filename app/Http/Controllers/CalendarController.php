<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $service_schedules = ServiceSchedule::with(['work_order', 'tenant', 'vendor'])->get();

        $events = $service_schedules->map(function ($schedule) {
            return [
                'title' => $schedule->title.' - '.'#'.$schedule->work_order->work_order_no,
                'with' => 'Tenant: '.$schedule->tenant->first_name.' '.$schedule->tenant->last_name,
                'time' => [
                    'start' => Carbon::parse($schedule->scheduled_date)->format('Y-m-d H:i'),
                    'end' => Carbon::parse($schedule->scheduled_date)->addHours(2)->format('Y-m-d H:i'),
                ],
                'id' => $schedule->id,
                'description' => 'Vendor: '.$schedule->vendor->name.' - '.$schedule->description ?? null,
            ];
        });

        return inertia('Calendar/Index', [
            'title' => 'Scheduled Service',
            'service_schedules' => $events,
        ]);
    }
}
