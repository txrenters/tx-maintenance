<?php

namespace App\Http\Controllers;

use App\Models\JobberVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InspectionVisitController extends Controller
{
    public function index(Request $request)
    {
        // Get week range from request or use current week
        $weekStart = $request->has('week_start')
            ? Carbon::parse($request->week_start)->timezone('America/Chicago')->startOfDay()
            : Carbon::now('America/Chicago')->startOfWeek(Carbon::SUNDAY);

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SATURDAY);

        // Load only this week's visits with minimal relationships
        $visits = JobberVisit::query()
            ->with(['job.client', 'job.property']) // Removed textMessages from eager loading
            ->filter(request(['search']))
            ->whereHas('job', function ($q) {
                $q->where('job_status', '!=', 'archived');
            })
            ->whereBetween('start_at', [$weekStart, $weekEnd])
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get();

        // Create minimal event objects for performance
        $events = $visits->map(function ($visit) {
            $startDate = Carbon::parse($visit->start_at);
            $endDate = Carbon::parse($visit->end_at);

            return [
                'id' => $visit->id,
                'title' => $visit->title,
                'start' => $startDate->format('Y-m-d H:i:s'), // Keep full datetime for proper timezone handling
                'end' => $endDate->format('Y-m-d H:i:s'),
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'job' => [
                    'id' => $visit->job->id,
                    'job_number' => $visit->job->job_number,
                    'title' => $visit->job->title,
                    'jobber_web_uri' => $visit->job->jobber_web_uri,
                ],
                'location' => optional($visit->job->property)->full_address ?? 'No Property',
                'address' => optional($visit->job->property)->full_address ?? 'No Property',
                'teamMember' => optional($visit->job->client)->name ?? 'No Client',
                // Don't include text messages in initial load
                'text_messages_count' => 0, // Will be loaded on demand
                'calendarId' => 'main',
                '_options' => [
                    'additionalClasses' => 'event_class',
                ],
            ];
        });

        return inertia('Inspection/Schedules', [
            'title' => 'Job Schedules',
            'events' => $events,
            'currentWeekRange' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
            ],
            'filters' => request(['search']),
        ]);
    }

    /**
     * Load events for a specific week range
     */
    public function weekData(Request $request)
    {
        $request->validate([
            'week_start' => 'required|date',
        ]);

        $weekStart = Carbon::parse($request->week_start)->timezone('America/Chicago')->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        $visits = JobberVisit::query()
            ->with(['job.client', 'job.property'])
            ->filter($request->only(['search']))
            ->whereHas('job', function ($q) {
                $q->where('job_status', '!=', 'archived');
            })
            ->whereBetween('start_at', [$weekStart, $weekEnd])
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get();

        $events = $visits->map(function ($visit) {
            $startDate = Carbon::parse($visit->start_at);
            $endDate = Carbon::parse($visit->end_at);

            return [
                'id' => $visit->id,
                'title' => $visit->title,
                'start' => $startDate->format('Y-m-d H:i:s'),
                'end' => $endDate->format('Y-m-d H:i:s'),
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'job' => [
                    'id' => $visit->job->id,
                    'job_number' => $visit->job->job_number,
                    'title' => $visit->job->title,
                    'jobber_web_uri' => $visit->job->jobber_web_uri,
                ],
                'location' => optional($visit->job->property)->full_address ?? 'No Property',
                'address' => optional($visit->job->property)->full_address ?? 'No Property',
                'teamMember' => optional($visit->job->client)->name ?? 'No Client',
                'text_messages_count' => 0,
                'calendarId' => 'main',
                '_options' => [
                    'additionalClasses' => 'event_class',
                ],
            ];
        });

        // Return Inertia response instead of JSON
        return inertia('Inspection/Schedules', [
            'title' => 'Job Schedules',
            'events' => $events,
            'currentWeekRange' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
            ],
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Get full visit details including messages (loaded on demand)
     */
    public function visitDetails($visitId)
    {
        $visit = JobberVisit::with(['job.client', 'job.property', 'job.textMessages', 'job.clientContacts'])
            ->findOrFail($visitId);

        $startDate = Carbon::parse($visit->start_at);
        $endDate = Carbon::parse($visit->end_at);

        return response()->json([
            'id' => $visit->id,
            'title' => $visit->title,
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
            'description' => $visit->instructions,
            'is_complete' => $visit->is_complete,
            'job' => [
                'id' => $visit->job->id,
                'job_number' => $visit->job->job_number,
                'title' => $visit->job->title,
                'jobber_web_uri' => $visit->job->jobber_web_uri,
                'client' => $visit->job->client,
                'property' => $visit->job->property,
            ],
            'text_messages_count' => $visit->job->textMessages()->count(),
            'text_messages' => $visit->job->textMessages->sortBy('created_at')->map(function ($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->messages,
                    'sender_number' => $message->sender_number,
                    'receiver_number' => $message->receiver_number,
                    'image' => $message->image ? asset('storage/'.$message->image) : null,
                    'is_mms' => ! empty($message->image),
                    'created_at' => $message->created_at,
                ];
            })->values(),
            'client_contacts' => $visit->job->clientContacts->map(function ($client) {
                return [
                    'id' => $client->id,
                    'client' => $client->name,
                    'phone' => $client->phone,
                ];
            }),
            'location' => optional($visit->job->property)->full_address ?? 'No Property',
            'people' => [$visit->job->client->name ?? 'No Client'],
        ]);
    }
}
