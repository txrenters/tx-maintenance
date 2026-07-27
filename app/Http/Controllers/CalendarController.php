<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    /**
     * Weekly, card-based view of scheduled service. Mirrors the Scheduled Visits
     * page: one column per day (Sun–Sat), each service schedule shown as a small
     * card on its scheduled day, with previous/next week navigation.
     */
    public function index(Request $request)
    {
        // Week range from the request, or the current week (Chicago timezone). The
        // frontend already sends the correct Sunday, so don't recalculate it.
        if ($request->filled('week_start')) {
            $weekStart = Carbon::parse($request->week_start, 'America/Chicago')->startOfDay();
        } else {
            $weekStart = Carbon::now('America/Chicago')->startOfWeek(Carbon::SUNDAY);
        }

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SATURDAY);

        $search = trim((string) $request->input('search', ''));

        $service_schedules = ServiceSchedule::with(['work_order.building', 'tenant', 'vendor'])
            ->whereBetween('scheduled_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhereHas('work_order', fn ($w) => $w
                            ->where('work_order_no', 'like', "%{$search}%")
                            ->orWhere('location', 'like', "%{$search}%"))
                        ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('tenant', fn ($t) => $t
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->get();

        $events = $service_schedules->map(function (ServiceSchedule $schedule) {
            // scheduled_date is date-only; anchor the card at 9:00 AM Chicago so the
            // frontend groups it on the correct calendar day.
            $start = Carbon::parse($schedule->scheduled_date, 'America/Chicago')->setTime(9, 0);

            // Prefer the building's real street address; fall back to the raw
            // work order location string only when no building is linked.
            $building = $schedule->work_order?->building;
            $address = $building
                ? collect([$building->address, $building->city, $building->state_region, $building->postal_code])
                    ->filter()
                    ->implode(', ')
                : null;

            return [
                'id' => $schedule->id,
                'start' => $start->toIso8601String(),
                'title' => $schedule->title,
                'status' => $schedule->status,
                'description' => $schedule->description,
                'work_order_id' => $schedule->work_order?->id,
                'work_order_no' => $schedule->work_order?->work_order_no,
                'location' => $address ?: $schedule->work_order?->location,
                'tenant' => $schedule->tenant
                    ? trim($schedule->tenant->first_name.' '.$schedule->tenant->last_name)
                    : null,
                'vendor' => $schedule->vendor?->name,
            ];
        })->values();

        return inertia('Calendar/Index', [
            'title' => 'Schedules',
            'events' => $events,
            'weekStart' => $weekStart->format('Y-m-d'),
            'currentWeekRange' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
            ],
            'filters' => ['search' => $search],
        ]);
    }
}
