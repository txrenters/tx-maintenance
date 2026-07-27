<?php

namespace App\Http\Controllers;

use App\Models\Tenants;
use App\Models\WorkOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class TenantsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('view_tenants', Tenants::class);

        $matchingTenantIds = Tenants::query()
            ->selectRaw('MIN(id)')
            ->filter($request->only('search'))
            ->groupBy('first_name', 'last_name', 'email');

        $tenants = Tenants::query()
            ->with('user')
            ->whereIn('id', $matchingTenantIds)
            ->orderBy('first_name', 'ASC')
            ->paginate(100)
            ->withQueryString()
            ->through(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->first_name.' '.$tenant->last_name,
                    'email' => $tenant->email,
                    'mobile_phone' => $tenant->mobile_phone,
                    'home_phone' => $tenant->home_phone,
                    'company' => $tenant->company,
                    'address' => $tenant->address ?: $tenant->user?->address,
                ];
            });

        return inertia('Tenant/Index', [
            'title' => 'Tenants',
            'tenants' => $tenants,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function show(Request $request, Tenants $tenant): Response
    {
        Gate::authorize('view_tenant', $tenant);

        $workOrders = WorkOrder::query()
            ->with('service_status:id,name')
            ->where(function ($query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)
                    ->orWhereHas('tenants', fn ($tenants) => $tenants->whereKey($tenant->id));
            })
            ->latest('created_date')
            ->get()
            ->map(fn (WorkOrder $workOrder): array => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'category' => $workOrder->category,
                'description' => $workOrder->description,
                'priority' => $workOrder->priority,
                'status' => $workOrder->service_status?->name ?? $workOrder->local_status,
                'created_date' => $workOrder->created_date,
            ]);

        $emailHistory = $tenant->emailNotifications()
            ->with(['attachments', 'jobberJob:id,job_number,title'])
            ->latest('sent_at')
            ->latest('id')
            ->get()
            ->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'direction' => $notification->direction,
                'subject' => $notification->subject,
                'body_html' => $notification->body_html,
                'body_text' => $notification->body_text,
                'from_email' => $notification->from_email,
                'to_email' => $notification->to_email,
                'sent_at' => $notification->sent_at?->toIso8601String(),
                'metadata' => $notification->metadata,
                'jobber_job_id' => $notification->jobber_job_id,
                'jobber_job' => $notification->jobberJob ? [
                    'id' => $notification->jobberJob->id,
                    'job_number' => $notification->jobberJob->job_number,
                    'title' => $notification->jobberJob->title,
                ] : null,
                'attachments' => $notification->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ]),
            ]);

        $jobberJobs = $tenant->jobberJobs()
            ->latest('start_at')
            ->get()
            ->map(fn ($job): array => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'title' => $job->title,
                'status' => $job->job_status,
                'start_at' => $job->start_at,
            ]);

        return inertia('Tenant/Show', [
            'title' => trim($tenant->first_name.' '.$tenant->last_name),
            'senderEmail' => (string) $request->user()->email,
            'tenant' => [
                'id' => $tenant->id,
                'name' => trim($tenant->first_name.' '.$tenant->last_name),
                'email' => $tenant->email,
                'mobile_phone' => $tenant->mobile_phone,
                'home_phone' => $tenant->home_phone,
                'work_phone' => $tenant->work_phone,
                'address' => collect([$tenant->address, $tenant->address2, $tenant->city, $tenant->state, $tenant->zip])
                    ->filter()
                    ->implode(', '),
                'company' => $tenant->company,
                'job_title' => $tenant->job_title,
                'propertyware_id' => $tenant->propertyware_id,
                'is_name_on_lease' => $tenant->is_name_on_lease,
                'comments' => $tenant->comments,
                'notes' => $tenant->notes,
            ],
            'emailHistory' => $emailHistory,
            'workOrders' => $workOrders,
            'jobberJobs' => $jobberJobs,
        ]);
    }

    public function destroy(Tenants $tenant): RedirectResponse
    {
        $tenant->delete();

        return redirect()->back();
    }

    public function bulkdelete(Request $request)
    {
        Tenants::whereIn('id', $request->tenantsId)->delete();

        return redirect()->back();

    }
}
