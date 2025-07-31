<?php

namespace App\Http\Controllers;

use App\Models\JobberVisit;
use Carbon\Carbon;

class InspectionVisitController extends Controller
{
    public function index()
    {
        $visits = JobberVisit::query()
            ->with(['job', 'job.client', 'job.property'])
            ->filter(request(['search']))
            ->whereHas('job', function ($q) {
                $q->whereNot('job_status', 'archived');
            })
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get();

        $events = $visits->map(function ($visit) {
            $startDate = Carbon::parse($visit->start_at);
            $start = $startDate->format('H:i') === '00:00'
                        ? $startDate->format('Y-m-d')
                        : $startDate->format('Y-m-d H:i');
            $endDate = Carbon::parse($visit->end_at);
            $end = $startDate->format('H:i') === '00:00'
                        ? $endDate->format('Y-m-d')
                        : $endDate->format('Y-m-d H:i');

            return [
                'id' => $visit->id,
                'title' => $visit->title,
                'start' => $start,
                'end' => $end,
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'job' => $visit->job->title,
                'job_number' => $visit->job->job_number,
                'jobber_web_uri' => $visit->job->jobber_web_uri,
                'location' => $visit->job->property ?
                        trim($visit->job->property->street.' '.$visit->job->property->city.' '.$visit->job->property->province.' '.$visit->job->property->postal_code.' '.$visit->job->property->country) : 'No Property',
                'people' => [$visit->job->client->name],
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
