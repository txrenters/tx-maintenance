<?php

namespace App\Http\Controllers;

use App\Jobs\ImportJobberDataJob;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobAttachment;
use App\Models\JobberJobInvoice;
use App\Models\JobberJobNote;
use App\Models\JobberTextMessage;
use App\Models\JobberToken;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\Vendor;
use App\Services\JobberTokenService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class InspectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorizeStaff($request);

        $jobsPerStatus = 20;

        $baseQuery = Jobber::query()
            ->filter($request->only(['search']))
            ->when($request->filled(['start_date', 'end_date']), function ($q) use ($request) {
                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();

                $q->where(function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_at', [$startDate, $endDate])
                        ->orWhereBetween('end_at', [$startDate, $endDate]);
                });
            })
            ->active();

        $statusCounts = (clone $baseQuery)
            ->selectRaw('job_status, COUNT(*) as total')
            ->groupBy('job_status')
            ->pluck('total', 'job_status');

        $allStatuses = $statusCounts->keys()->sortDesc()->values();

        $jobsByStatus = $allStatuses->mapWithKeys(function ($status) use ($baseQuery, $jobsPerStatus) {
            $jobs = (clone $baseQuery)
                ->where('job_status', $status)
                ->with(['client', 'vendors:id,name'])
                ->withCount('visits')
                ->orderBy('start_at', 'desc')
                ->limit($jobsPerStatus)
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
                        'client_name' => trim(($job->client?->first_name ?? '').' '.($job->client?->last_name ?? '')) ?: 'No Client',
                        'visits_count' => $job->visits_count ?? 0,
                        // Names only: this payload is deliberately slim after the
                        // board memory-exhaustion incident.
                        'vendor_names' => $job->vendors->pluck('name')->implode(', '),
                    ];
                });

            return [$status => $jobs];
        });

        $statistics = [
            'total_jobs' => $statusCounts->sum(),
            'status_counts' => $statusCounts,
            'statuses' => $allStatuses,
        ];

        return inertia('Inspection/Index', [
            'title' => 'All Jobs',
            'jobsByStatus' => Inertia::defer(fn () => $jobsByStatus),
            'statistics' => $statistics,
            'access_token_exist' => $this->accessTokenExist(),
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function destroy(Request $request, Jobber $inspection)
    {
        $this->authorizeStaff($request);

        $inspection->delete();

        return redirect()->back()->with('success', 'Deleted successfully!');
    }

    public function jobDetails(Request $request, Jobber $job)
    {
        $this->authorizeStaff($request);

        $payload = $this->buildJobDetailsPayload($job);

        $vendorOptions = Vendor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Anyone in the office may assign a vendor and handle documentation on
        // these jobs; only outside parties are kept out. One flag drives both
        // so the two never drift apart.
        $isStaff = (bool) $request->user()?->isStaff();

        // The board modal fetches this as JSON and renders the same vendor,
        // photo and invoice tabs, so it needs the picker and the gates too.
        if ($request->expectsJson() || $request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($payload + [
                'vendor_options' => $vendorOptions,
                'can_assign_vendors' => $isStaff,
                'can_upload_invoices' => $isStaff,
                'can_close' => $isStaff,
                'can_manage_notes' => $isStaff,
            ]);
        }

        return inertia('Inspection/Show', [
            'title' => 'Job #'.$job->job_number,
            'vendorOptions' => $vendorOptions,
            'canAssignVendors' => $isStaff,
            'canUploadInvoices' => $isStaff,
            'canClose' => $isStaff,
            'canManageNotes' => $isStaff,
            'job' => array_merge([
                'id' => $job->id,
                'job_number' => $job->job_number,
                'title' => $job->title,
                'job_status' => $job->job_status,
                'job_type' => $job->job_type,
                'total' => $job->total,
                'start_at' => $job->start_at,
                'client_name' => trim(($job->client?->first_name ?? '').' '.($job->client?->last_name ?? '')) ?: null,
            ], $payload),
        ]);
    }

    protected function buildJobDetailsPayload(Jobber $job): array
    {
        $job->load(['visits', 'client', 'property', 'textMessages', 'clientContacts', 'vendors', 'jobAttachments', 'jobInvoices.vendor', 'jobNotes.user:id,name', 'closedBy:id,name']);

        return [
            'is_closed' => $job->isClosedLocally(),
            'closed_at' => $job->closed_at,
            'closed_by' => $job->closedBy?->name,
            'close_reason' => $job->close_reason,
            'vendors' => $job->vendors->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'has_email' => filled($vendor->email),
                'portal_url' => $vendor->pivot->access_token
                    ? route('jobber.portal.show', $vendor->pivot->access_token)
                    : null,
            ])->values(),
            'vendor_ids' => $job->vendors->pluck('id')->values(),
            'attachments' => $job->jobAttachments->sortByDesc('created_at')->map(fn (JobberJobAttachment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'uploaded_via' => $a->uploaded_via,
                'url' => asset('storage/'.$a->filename),
                'is_image' => $a->isImage(),
                'created_at' => $a->created_at,
            ])->values(),
            'invoices' => $job->jobInvoices->sortByDesc('created_at')->map(fn (JobberJobInvoice $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'amount' => $i->amount,
                'status' => $i->status,
                'vendor_name' => $i->vendor?->name,
                'url' => asset('storage/'.$i->filename),
                'created_at' => $i->created_at,
            ])->values(),
            // Newest first by id rather than created_at: two notes written in
            // the same second would otherwise come back oldest-first.
            'notes' => $job->jobNotes->sortByDesc('id')->map(fn (JobberJobNote $n) => [
                'id' => $n->id,
                'body' => $n->body,
                'author' => $n->user?->name ?? 'Unknown',
                'created_at' => $n->created_at,
            ])->values(),
            'notes_count' => $job->jobNotes->count(),
            'jobber_web_uri' => $job->jobber_web_uri,
            'instructions' => $job->instructions,
            'end_at' => $job->end_at,
            'completed_at' => $job->completed_at,
            'client' => $job->client ?? null,
            'client_id' => $job->client?->id,
            'phone' => $job->client?->phone,
            'client_company' => $job->client?->company_name,
            'property_id' => $job->property?->id,
            'property_address' => $job->property ?
                trim($job->property->street.' '.$job->property->city.' '.$job->property->province.' '.$job->property->postal_code.' '.$job->property->country)
                : 'No Property',
            'visits' => $job->visits,
            'visits_count' => $job->visits->count(),
            'text_messages' => $job->textMessages->sortBy('created_at')->map(function ($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->messages,
                    'messages' => $message->messages,
                    'sender_number' => $message->sender_number,
                    'receiver_number' => $message->receiver_number,
                    'image' => $message->image ? asset('storage/'.$message->image) : null,
                    'is_mms' => ! empty($message->image),
                    'created_at' => $message->created_at,
                    'sent_at' => $message->sent_at,
                    'twilio_status' => $message->twilio_status,
                    'twilio_error_code' => $message->twilio_error_code,
                    'twilio_error_message' => $message->twilio_error_message,
                    'twilio_sid' => $message->twilio_sid,
                ];
            })->values(),
            'text_messages_count' => $job->textMessages->count(),
            'client_contacts' => $job->clientContacts->map(function ($client) {
                return [
                    'id' => $client->id,
                    'client' => $client->name,
                    'phone' => $client->phone,
                ];
            })->values(),
        ];
    }

    public function messages(Request $request)
    {
        $convos = JobberTextMessage::query()
            ->with(['jobber.client', 'jobber.property', 'jobber.visits'])
            ->latest()
            ->filter(request(['search']))
            ->when($request->filled('jobber_id'), function ($q) use ($request) {
                $q->where('jobber_id', $request->jobber_id);
            })
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
                    'created_at' => $convo->created_at->tz('America/Chicago')->format('F d, Y h:i A'),
                ];
            });

        // Get list of jobs that have messages for the filter dropdown
        $jobs = Jobber::query()
            ->whereHas('textMessages')
            ->where('job_status', '!=', 'archived')
            ->select('id', 'job_number', 'title')
            ->orderBy('job_number', 'desc')
            ->get();

        return inertia('Inspection/Messages', [
            'title' => 'Jobs Messages',
            'conversations' => $convos,
            'jobs' => $jobs,
            'filters' => $request->only(['search', 'jobber_id']),
        ]);
    }

    /**
     * A logged-in vendor's own Jobber jobs.
     *
     * Fails closed to an empty list when the user has no vendor record, rather
     * than 500-ing, matching how the attachment/invoice scopes behave.
     */
    public function vendorJobs(Request $request)
    {
        $vendor = Vendor::where('user_id', $request->user()->id)->first();

        $jobs = $vendor
            ? Jobber::query()
                ->forVendor($vendor)
                ->with(['client', 'property'])
                ->active()
                ->orderByDesc('start_at')
                ->get()
                ->map(fn (Jobber $job) => [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'title' => $job->title,
                    'job_status' => $job->job_status,
                    'start_at' => $job->start_at,
                    'property_address' => $job->property?->full_address,
                    'client_name' => trim(($job->client?->first_name ?? '').' '.($job->client?->last_name ?? '')) ?: null,
                    'portal_url' => $job->vendors->firstWhere('id', $vendor->id)?->pivot?->access_token
                        ? route('jobber.portal.show', $job->vendors->firstWhere('id', $vendor->id)->pivot->access_token)
                        : null,
                ])->values()
            : collect();

        return inertia('Inspection/VendorJobs', [
            'title' => 'My Jobs',
            'jobs' => $jobs,
        ]);
    }

    public function accessTokenExist()
    {
        return JobberToken::whereNotNull('access_token')->exists();
    }

    /**
     * The Jobs pages are unscoped across every outside client, so only staff
     * may reach them. Vendors get their own scoped list via vendorJobs() and
     * the magic-link portal; tenants and owners have no business here at all.
     */
    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->isStaff(), 403);
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
            'client_id' => config('services.jobber.client_id'),
            'redirect_uri' => config('services.jobber.callback_url'),
            'state' => $state,
        ]);

        return redirect("https://api.getjobber.com/api/oauth/authorize?$query");
    }

    public function manualSyncJobber(Request $request)
    {
        // Check if user is admin
        if (! $request->user()->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            // A valid token (refreshed here if needed) is required before syncing.
            try {
                app(JobberTokenService::class)->getAccessToken();
            } catch (\Exception $tokenException) {
                return response()->json([
                    'error' => 'Invalid token',
                    'message' => 'Jobber connection expired. Please reconnect.',
                    'needs_reconnect' => true,
                ], 401);
            }

            // A full account walk takes minutes, so it runs on the queue. Doing
            // it inline held the request open until Azure or the browser cut it.
            ImportJobberDataJob::dispatch();

            return response()->json([
                'success' => true,
                'message' => 'Jobber sync started. New jobs will appear as they import.',
            ], 202);
        } catch (\Exception $e) {
            // Check if it's a token-related error
            if (str_contains($e->getMessage(), 'token') || str_contains($e->getMessage(), 'reconnect')) {
                return response()->json([
                    'error' => 'Authentication failed',
                    'message' => 'Please reconnect to Jobber',
                    'needs_reconnect' => true,
                ], 401);
            }

            return response()->json([
                'error' => 'Failed to sync with Jobber',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
