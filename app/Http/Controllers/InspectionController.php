<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use Illuminate\Http\Request;

class InspectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get all jobs with relationships (no pagination for grouping)
        $jobs = Jobber::query()
            ->with(['client', 'property', 'visits', 'textMessages'])
            ->filter(request(['search'])) // Add search filter if needed
            ->whereNot('job_status', 'archived')
            ->latest()
            ->get()
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'title' => $job->title,
                    'job_status' => $job->job_status,
                    'job_type' => $job->job_type,
                    'jobber_web_uri' => $job->jobber_web_uri,
                    'total' => $job->total,
                    'instructions' => $job->instructions,
                    'start_at' => $job->start_at,
                    'end_at' => $job->end_at,
                    'completed_at' => $job->completed_at,
                    'client_id' => $job->client->id ?? null,
                    'client_name' => $job->client->name ?? 'No Client',
                    'client_company' => $job->client->company_name ?? null,
                    'property_id' => $job->property->id ?? null,
                    'property_address' => $job->property ? 
                        trim($job->property->street . ' ' . $job->property->city . ' ' . $job->property->province . ' ' . $job->property->postal_code . ' ' . $job->property->country) 
                        : 'No Property',
                    'visits' => $job->visits,
                    'visits_count' => $job->visits->count(),
                    'text_messages' => $job->textMessages->sortBy('created_at')->map(function ($message) {
                        return [
                            'id' => $message->id,
                            'message' => $message->messages,
                            'sender_number' => $message->sender_number,
                            'receiver_number' => $message->receiver_number,
                            'image' => $message->image ? asset('storage/' . $message->image) : null,
                            'is_mms' => !empty($message->image), // Set MMS flag for images
                            'created_at' => $message->created_at,
                        ];
                    }),
                    'text_messages_count' => $job->textMessages->count(),
                ];
            });

        // Group jobs by status
        $groupedJobs = $jobs->groupBy('job_status');

        // Get all unique statuses and ensure consistent ordering
        $allStatuses = $jobs->pluck('job_status')->unique()->sort()->values();

        // Format the grouped data for frontend
        $jobsByStatus = $allStatuses->mapWithKeys(function ($status) use ($groupedJobs) {
            return [$status => $groupedJobs->get($status, collect())];
        });

        // Get statistics
        $statistics = [
            'total_jobs' => $jobs->count(),
            'status_counts' => $jobs->countBy('job_status'),
            'statuses' => $allStatuses,
        ];

        return inertia('Inspection/Index', [
            'title' => 'Inspections',
            'jobsByStatus' => $jobsByStatus,
            'statistics' => $statistics,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
