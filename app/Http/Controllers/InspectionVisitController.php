<?php

namespace App\Http\Controllers;

use App\Models\JobberVisit;
use Carbon\Carbon;
use Inertia\Inertia;

class InspectionVisitController extends Controller
{
    public function index()
    {
        $visits = JobberVisit::query()
            ->with(['job.client', 'job.property','job.textMessages'])
            ->filter(request(['search']))
            ->whereHas('job', function ($q) {
                $q->where('job_status', '!=', 'archived');
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
                'start' =>$startDate->format('Y-m-d') ,
                'end' =>$endDate->format('Y-m-d') ,
                'description' => $visit->instructions,
                'is_complete' => $visit->is_complete,
                'job' => $visit->job,
                'text_messages_count' => $visit->job->textMessages()->count(),
                'location' => optional($visit->job->property)->full_address ?? 'No Property',
                'people' => [$visit->job->client->name ?? 'No Client'],
                'text_messages' => $visit->job->textMessages->sortBy('created_at')->map(function ($message) {
                    return [
                            'id' => $message->id,
                            'message' => $message->messages,
                            'sender_number' => $message->sender_number,
                            'receiver_number' => $message->receiver_number,
                            'image' => $message->image ? asset('storage/'.$message->image) : null,
                            'is_mms' => ! empty($message->image), // Set MMS flag for images
                            'created_at' => $message->created_at,
                        ];
                }),
                'client_contacts' => $visit->job->clientContacts->map(function ($client) {
                    return [
                        'id' => $client->id,
                        'client' => $client->name,
                        'phone' => $client->phone,
                    ];
                }),
                'calendarId' => 'main',
                '_options' => [
                    'additionalClasses' => 'event_class',
                ],
            ];
        });

        return inertia('Inspection/Schedules', [
            'title' => 'Job Schedules',
            'visits' => Inertia::defer(fn() => $visits) ,
            'events' => $events ,
            'filters' => request(['search']),
        ]);
    }

     public function visitDetails(JobberVisit $visit)
    {
        $visit->load(['job.client', 'job.property', 'job.textMessages', 'job.clientContacts']);

         $startDate = Carbon::parse($visit->start_at);
        $endDate = Carbon::parse($visit->end_at);

        $isAllDay = $visit->all_day;

        return response()->json([
            'id' => $visit->id,
            'title' => $visit->title,
            'start' => $isAllDay ? $startDate->format('Y-m-d') : $startDate->format('Y-m-d H:i'),
            'end' => $isAllDay ? $endDate->format('Y-m-d') : $endDate->format('Y-m-d H:i'),
            'description' => $visit->instructions,
            'is_complete' => $visit->is_complete,
            'job' => $visit->job,
            'text_messages_count' => $visit->job->textMessages()->count(),
            'text_messages' => $visit->job->textMessages,
            'location' => optional($visit->job->property)->full_address ?? 'No Property',
            'people' => [$visit->job->client->name ?? 'No Client'],
            'text_messages' => $visit->job->textMessages->sortBy('created_at')->map(function ($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->messages,
                    'sender_number' => $message->sender_number,
                    'receiver_number' => $message->receiver_number,
                    'image' => $message->image ? asset('storage/'.$message->image) : null,
                    'is_mms' => ! empty($message->image), // Set MMS flag for images
                    'created_at' => $message->created_at,
                ];
            }),

            'client_contacts' => $visit->job->clientContacts->map(function ($client) {
                return [
                    'id' => $client->id,
                    'client' => $client->name,
                    'phone' => $client->phone,
                ];
            }),
        ]);
    }
}
