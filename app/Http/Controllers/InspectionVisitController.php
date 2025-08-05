<?php

namespace App\Http\Controllers;

use App\Models\JobberVisit;
use Carbon\Carbon;

class InspectionVisitController extends Controller
{
    public function index()
    {
        $visits = JobberVisit::query()
            ->with(['job.client', 'job.property'])
            ->filter(request(['search']))
            ->whereHas('job', function ($q) {
                $q->where('job_status', '!=', 'archived');
            })
            ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                $start_date = Carbon::parse(request('start_date'))->startOfDay();
                $end_date = Carbon::parse(request('end_date'))->endOfDay();

                $q->where(function ($query) use ($start_date, $end_date) {
                    $query->whereBetween('start_at', [$start_date, $end_date])
                        ->orWhereBetween('end_at', [$start_date, $end_date]);
                });
            })
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get();

        $events = $visits->map(function ($visit) {
            $startDate = Carbon::parse($visit->start_at);
            $endDate = Carbon::parse($visit->end_at);

            $isAllDay = $visit->all_day;

            return [
                'id' => $visit->id,
                'title' => $visit->title,
                'start' => $isAllDay ? $startDate->format('Y-m-d') : $startDate->format('Y-m-d H:i'),
                'end' => $isAllDay ? $endDate->format('Y-m-d') : $endDate->format('Y-m-d H:i'),
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'job' => $visit->job,
                'location' => optional($visit->job->property)->full_address ?? 'No Property',
                'people' => [$visit->job->client->name ?? 'No Client'],
                'calendarId' => 'main',
                '_options' => [
                    'additionalClasses' => 'event_class',
                ],
            ];
        });

        return inertia('Inspection/Schedules', [
            'title' => 'Job Schedules',
            'events' => $events,
            'filters' => request(['search']),
        ]);
    }
}
