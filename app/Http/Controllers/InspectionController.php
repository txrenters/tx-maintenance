<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberTextMessage;
use App\Models\JobberToken;
use App\Models\JobberVisit;
use App\Models\Owner;
use App\Models\Tenants;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;

class InspectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $jobs = Jobber::query()
            ->with(['client', 'visits'])
            ->filter(request(['search'])) // Add search filter if needed
            ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                $start_date = Carbon::parse(request('start_date'))->startOfDay();
                $end_date = Carbon::parse(request('end_date'))->endOfDay();

                $q->where(function ($query) use ($start_date, $end_date) {
                    $query->whereBetween('start_at', [$start_date, $end_date])
                        ->orWhereBetween('end_at', [$start_date, $end_date]);
                });
            })
            ->whereNotIn('job_status', ['archived', 'closed'])
            ->orderBy('start_at', 'desc')
            ->get()
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'title' => $job->title,
                    'job_status' => $job->job_status,
                    'job_type' => $job->job_type,
                    'total' => $job->total,
                    'start_at' => $job->start_at,
                    'client_name' => $job->client->first_name.' '.$job->client->last_name ?? 'No Client',
                    'visits_count' => $job->visits->count(),
                ];
            });

        $groupedJobs = $jobs->groupBy('job_status');

        $allStatuses = $jobs->pluck('job_status')->unique()->sortDesc()->values();

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
            'jobsByStatus' => Inertia::defer(fn () => $jobsByStatus),
            'statistics' => $statistics,
            'access_token_exist' => $this->accessTokenExist(),
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function jobDetails(Jobber $job)
    {
        $job->load(['visits', 'client', 'property', 'textMessages', 'clientContacts']);

        return response()->json([
            'jobber_web_uri' => $job->jobber_web_uri,
            'instructions' => $job->instructions,
            'end_at' => $job->end_at,
            'completed_at' => $job->completed_at,
            'client' => $job->client ?? null,
            'client_id' => $job->client->id ?? null,
            'phone' => $job->client->phone,
            'client_company' => $job->client->company_name ?? null,
            'property_id' => $job->property->id ?? null,
            'property_address' => $job->property ?
                trim($job->property->street.' '.$job->property->city.' '.$job->property->province.' '.$job->property->postal_code.' '.$job->property->country)
                : 'No Property',
            'visits' => $job->visits,
            'visits_count' => $job->visits->count(),
            'text_messages' => $job->textMessages->sortBy('created_at')->map(function ($message) {
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
            'text_messages_count' => $job->textMessages->count(),
            'client_contacts' => $job->clientContacts->map(function ($client) {
                return [
                    'id' => $client->id,
                    'client' => $client->name,
                    'phone' => $client->phone,
                ];
            }),
        ]);
    }

    public function messages(Request $request)
    {
        // $visits = JobberVisit::query()
        //     ->with(['job.client', 'job.property', 'job.textMessages'])
        //     ->tbp()
        //     ->latest()
        //     ->filter(request(['search']))
        //     ->whereHas('job', function ($q) {
        //         $q->where('job_status', '!=', 'archived');
        //     })
        //     // ->orderByRaw('ABS(DATEDIFF(jobber_visits.start_at, CURDATE())) ASC') // closest to today
        //     ->paginate(50)
        //     ->withQueryString()
        //     ->through(function ($visit) {
        //         return [
        //             'id' => $visit->id,
        //             'job_number' => $visit->job->job_number,
        //             'job_title' => $visit->job->title,
        //             'visit_title' => $visit->title,
        //             'start_at' => $visit->start_at,
        //             'client' => $visit->job->client->title,
        //             'messages' => $visit->job?->textMessages,
        //             'created_at' => $visit->created_at->timezone('America/Chicago')->format('F d, y h:i A'),
        //         ];
        //     });

        $convos = JobberTextMessage::query()
            ->with(['jobber.client', 'jobber.property', 'jobber.visits'])
            ->latest()
            ->filter(request(['search']))
            ->whereHas('jobber', function ($q) {
                $q->where('job_status', '!=', 'archived');
            })
            // ->orderByRaw('ABS(DATEDIFF(jobber_visits.start_at, CURDATE())) ASC') // closest to today
            ->paginate(50)
            ->withQueryString()
            ->through(function ($convo) {
                return [
                    'id' => $convo->id,
                    'job_number' => $convo->jobber->job_number,
                    'job_title' => $convo->jobber->title,
                    'visits' => $convo->jobber->visits,
                    'sender' => $convo->sender_number,
                    'receiver' => $convo->receiver_number,
                    'messages' => $convo->messages,
                    'created_at' => $convo->created_at->timezone('America/Chicago')->format('F d, y h:i A'),
                ];
            });

        return inertia('Inspection/Messages', [
            'title' => 'Jobber Messages',
            'conversations' => $convos,
        ]);
    }

    public function accessTokenExist()
    {
        return JobberToken::whereNotNull('access_token')->exists();
    }

    public function searchClient(Request $request)
    {

        $search = $request->search;

        $tenants = Tenants::select('id', 'first_name', 'last_name', 'home_phone', 'mobile_phone', 'work_phone')
            ->where('first_name', 'like', "%$request->search%")
            ->orWhere('last_name', 'like', "%$request->search%")
            ->orWhere('home_phone', 'like', "%$request->search%")
            ->orWhere('mobile_phone', 'like', "%$request->search%")
            ->orWhere('work_phone', 'like', "%$request->search%")
            ->orWhere('last_name', 'like', "%$request->search%")
            ->get()
            ->flatMap(function ($tenant) {
                $matches = collect();
                $phones = [
                    'home_phone' => $tenant->home_phone,
                    'mobile_phone' => $tenant->mobile_phone,
                    'work_phone' => $tenant->work_phone,
                ];

                foreach ($phones as $number) {
                    if ($number) {
                        $matches->push((object) [
                            'id' => $tenant->id,
                            'first_name' => $tenant->first_name,
                            'last_name' => $tenant->last_name,
                            'phone' => $number,
                        ]);
                    }
                }

                return $matches;
            });

        $owners = Owner::select('id', 'first_name', 'last_name', 'home_phone', 'mobile_phone', 'work_phone')
            ->where('first_name', 'like', "%$request->search%")
            ->orWhere('last_name', 'like', "%$request->search%")
            ->orWhere('home_phone', 'like', "%$request->search%")
            ->orWhere('mobile_phone', 'like', "%$request->search%")
            ->orWhere('work_phone', 'like', "%$request->search%")
            ->orWhere('last_name', 'like', "%$request->search%")
            ->get()
            ->flatMap(function ($owner) {
                $matches = collect();
                $phones = [
                    'home_phone' => $owner->home_phone,
                    'mobile_phone' => $owner->mobile_phone,
                    'work_phone' => $owner->work_phone,
                ];

                foreach ($phones as $number) {
                    if ($number) {
                        $matches->push((object) [
                            'id' => $owner->id,
                            'first_name' => $owner->first_name,
                            'last_name' => $owner->last_name,
                            'phone' => $number,
                        ]);
                    }
                }

                return $matches;
            });

        $clients = $tenants->merge($owners);

        $distinctClients = $clients->unique(function ($client) {
            return strtolower(trim($client->phone));
        })->values();

        return response()->json($distinctClients);

    }

    public function saveClient(Request $request)
    {

        $validated = $request->validate([
            'jobber_id' => 'required|integer',
            'client.first_name' => 'required|string|max:255',
            'client.last_name' => 'required|string|max:255',
            'client.phone' => 'required|string|max:50',
        ]);
        // ✅ Load jobber with its client
        $jobber = Jobber::with('client')->findOrFail($validated['jobber_id']);

        // ✅ Make sure the client relationship exists
        if (! $jobber->client) {
            return response()->json(['error' => 'Client not found for this jobber.'], 404);
        }

        JobberClient::find($jobber->jobber_client_id)->update([
            'first_name' => $validated['client']['first_name'],
            'last_name' => $validated['client']['last_name'],
            'phone' => $validated['client']['phone'],
        ]);

        return response()->json(['success' => true], 200);

    }

    public function redirectToJobber()
    {
        $state = Str::random(32);
        session(['jobber_oauth_state' => $state]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => env('JOBBER_CLIENT_ID'),
            'redirect_uri' => env('JOBBER_CALLBACK_URL'),
            'state' => $state,
        ]);

        return redirect("https://api.getjobber.com/api/oauth/authorize?$query");
    }
}
