<?php

namespace App\Http\Controllers;

use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Services\JobberWorkOrderResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InspectionVisitController extends Controller
{
    public function index(Request $request, JobberWorkOrderResolver $workOrders)
    {
        // Get week range from request or use current week (always in Chicago timezone)
        if ($request->has('week_start') && $request->week_start) {
            // Parse the date as if it's already in Chicago timezone (don't convert)
            // Frontend already sends the correct Sunday, so don't recalculate startOfWeek
            $weekStart = Carbon::parse($request->week_start, 'America/Chicago');
        } else {
            $weekStart = Carbon::now('America/Chicago')->startOfWeek(Carbon::SUNDAY);
        }

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SATURDAY);

        // Load only this week's visits with minimal relationships
        $visits = JobberVisit::query()
            ->with(['job.client', 'job.property'])
            ->filter(request(['search']))
            ->whereHas('job', function ($q) {
                $q->where('job_status', '!=', 'archived');
            })
            ->whereBetween('start_at', [$weekStart, $weekEnd])
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get();

        // The work order behind each visit (null for TBP visits), resolved
        // once for the whole week so the modal's number can open it.
        $linkedWorkOrders = $workOrders->forVisits($visits);

        // Create minimal event objects for performance
        $events = $visits->map(function ($visit) use ($linkedWorkOrders) {
            // Database stores dates as Chicago time (from Jobber sync)
            // Create Carbon instances explicitly in Chicago timezone
            $startDate = Carbon::createFromFormat('Y-m-d H:i:s', $visit->start_at, 'America/Chicago');
            $endDate = Carbon::createFromFormat('Y-m-d H:i:s', $visit->end_at, 'America/Chicago');

            return [
                'id' => $visit->id,
                'title' => $visit->title,
                'start' => $startDate->toIso8601String(), // ISO 8601 with Chicago timezone
                'end' => $endDate->toIso8601String(),
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'notified_14_days' => $visit->notified_14_days ?? false,
                'notified_7_days' => $visit->notified_7_days,
                'notified_3_days' => $visit->notified_3_days,
                'notified_1_days' => $visit->notified_1_days,
                'job' => [
                    'id' => $visit->job->id,
                    'job_number' => $visit->job->job_number,
                    'title' => $visit->job->title,
                    'jobber_web_uri' => $visit->job->jobber_web_uri,
                ],
                'work_order' => $linkedWorkOrders[$visit->id] ?? null,
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
            'title' => 'Scheduled Visits',
            'events' => $events,
            'currentWeekRange' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
            ],
            'weekStart' => $weekStart->format('Y-m-d'), // Add this for frontend initialization
            'filters' => request(['search']),
        ]);
    }

    /**
     * Get full visit details including messages (loaded on demand)
     */
    public function visitDetails($visitId, JobberWorkOrderResolver $workOrders)
    {
        $visit = JobberVisit::with(['job.client', 'job.property', 'job.clientContacts'])
            ->findOrFail($visitId);

        // Returned here too: the page merges this JSON over the event it
        // already has, so a missing key would drop the link.
        $linkedWorkOrder = $workOrders->forVisits(collect([$visit]))[$visit->id] ?? null;

        // Scope messages to the selected visit window, not the entire job history.
        $jobVisits = $visit->job->visits()
            ->whereNotNull('start_at')
            ->orderBy('start_at')
            ->get()
            ->values();

        $currentIndex = $jobVisits->search(fn ($jobVisit) => $jobVisit->id === $visit->id);

        $currentStartChicago = Carbon::createFromFormat('Y-m-d H:i:s', $visit->start_at, 'America/Chicago');
        $currentEndChicago = $visit->end_at
            ? Carbon::createFromFormat('Y-m-d H:i:s', $visit->end_at, 'America/Chicago')
            : $currentStartChicago->copy();

        $windowStartChicago = $currentStartChicago->copy()->subDays(14);
        $windowEndChicago = $currentEndChicago->copy()->addDays(14);

        if ($currentIndex !== false) {
            if ($currentIndex > 0) {
                $prevStart = Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $jobVisits[$currentIndex - 1]->start_at,
                    'America/Chicago'
                );
                $secondsBetweenPrevAndCurrent = $prevStart->diffInSeconds($currentStartChicago);
                $windowStartChicago = $prevStart->copy()->addSeconds((int) floor($secondsBetweenPrevAndCurrent / 2));
            }

            if ($currentIndex < $jobVisits->count() - 1) {
                $nextStart = Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $jobVisits[$currentIndex + 1]->start_at,
                    'America/Chicago'
                );
                $secondsBetweenCurrentAndNext = $currentStartChicago->diffInSeconds($nextStart);
                $windowEndChicago = $currentStartChicago->copy()->addSeconds((int) floor($secondsBetweenCurrentAndNext / 2));
            }
        }

        $windowStartUtc = $windowStartChicago->copy()->setTimezone('UTC');
        $windowEndUtc = $windowEndChicago->copy()->setTimezone('UTC');

        $visitScopedMessagesQuery = JobberTextMessage::query()
            ->where('jobber_id', $visit->job->id);

        if (JobberTextMessage::hasVisitColumn()) {
            $visitScopedMessagesQuery->where(function ($query) use ($visit, $windowStartUtc, $windowEndUtc) {
                $query->where('jobber_visit_id', $visit->id)
                    ->orWhere(function ($legacyQuery) use ($windowStartUtc, $windowEndUtc) {
                        $legacyQuery->whereNull('jobber_visit_id')
                            ->whereBetween('created_at', [$windowStartUtc, $windowEndUtc]);
                    });
            });
        } else {
            $visitScopedMessagesQuery->whereBetween('created_at', [$windowStartUtc, $windowEndUtc]);
        }

        $visitScopedMessages = $visitScopedMessagesQuery
            ->orderBy('created_at')
            ->get();

        // Database stores dates as Chicago time - create Carbon instances in Chicago timezone
        $startDate = Carbon::createFromFormat('Y-m-d H:i:s', $visit->start_at, 'America/Chicago');
        $endDate = Carbon::createFromFormat('Y-m-d H:i:s', $visit->end_at, 'America/Chicago');

        return response()->json([
            'id' => $visit->id,
            'title' => $visit->title,
            'start' => $startDate->toIso8601String(),
            'end' => $endDate->toIso8601String(),
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
            'work_order' => $linkedWorkOrder,
            'text_messages_count' => $visitScopedMessages->count(),
            'text_messages' => $visitScopedMessages->map(function ($message) {
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
