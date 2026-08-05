<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin IT tools page listing every automated message the app has sent to
 * owners, tenants, and vendors — audience, channel, automation, recipient,
 * work order, and timestamp — from the activity_log ledger written by
 * AutomatedMessageLogService at each send.
 */
class AutomatedMessageLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $filters = [
            'audience' => (string) $request->input('audience', 'all'),
            'channel' => (string) $request->input('channel', 'all'),
            'automation' => (string) $request->input('automation', 'all'),
            'work_order' => trim((string) $request->input('work_order', '')),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        if (! in_array($filters['audience'], ['all', 'owner', 'tenant', 'vendor'], true)) {
            $filters['audience'] = 'all';
        }
        if (! in_array($filters['channel'], ['all', 'sms', 'email'], true)) {
            $filters['channel'] = 'all';
        }
        if ($filters['automation'] !== 'all' && ! array_key_exists($filters['automation'], AutomatedMessageLogService::AUTOMATIONS)) {
            $filters['automation'] = 'all';
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(10, min($perPage, 100));

        $query = Activity::query()->where('log_name', AutomatedMessageLogService::LOG_NAME);

        $this->applyFilters($query, $filters);

        $statsRow = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN description LIKE 'owner:%' THEN 1 ELSE 0 END) as owner_total")
            ->selectRaw("SUM(CASE WHEN description LIKE 'tenant:%' THEN 1 ELSE 0 END) as tenant_total")
            ->selectRaw("SUM(CASE WHEN description LIKE 'vendor:%' THEN 1 ELSE 0 END) as vendor_total")
            ->selectRaw("SUM(CASE WHEN description LIKE '%:sms' THEN 1 ELSE 0 END) as sms_total")
            ->selectRaw("SUM(CASE WHEN description LIKE '%:email' THEN 1 ELSE 0 END) as email_total")
            ->first();

        $logs = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Activity $activity) => $this->presentRow($activity));

        return Inertia::render('ItTools/AutomatedMessages', [
            'title' => 'IT Tools — Automated Messages',
            'logs' => $logs,
            'stats' => [
                'total' => (int) ($statsRow->total ?? 0),
                'owner' => (int) ($statsRow->owner_total ?? 0),
                'tenant' => (int) ($statsRow->tenant_total ?? 0),
                'vendor' => (int) ($statsRow->vendor_total ?? 0),
                'sms' => (int) ($statsRow->sms_total ?? 0),
                'email' => (int) ($statsRow->email_total ?? 0),
            ],
            'filters' => array_merge($filters, ['per_page' => $perPage]),
            'automations' => AutomatedMessageLogService::AUTOMATIONS,
        ]);
    }

    /**
     * All filters resolve without JSON functions: audience/channel live in the
     * indexed-friendly "{audience}:{channel}" description, the automation key
     * in event, and the work order through the subject morph index.
     */
    private function applyFilters($query, array $filters): void
    {
        $audience = $filters['audience'];
        $channel = $filters['channel'];

        if ($audience !== 'all' && $channel !== 'all') {
            $query->where('description', $audience.':'.$channel);
        } elseif ($audience !== 'all') {
            $query->where('description', 'like', $audience.':%');
        } elseif ($channel !== 'all') {
            $query->where('description', 'like', '%:'.$channel);
        }

        if ($filters['automation'] !== 'all') {
            $query->where('event', $filters['automation']);
        }

        if ($filters['work_order'] !== '') {
            $workOrderIds = WorkOrder::query()
                ->withoutGlobalScopes()
                ->where('work_order_no', 'like', '%'.$filters['work_order'].'%')
                ->limit(50)
                ->pluck('id');

            $query->where('subject_type', WorkOrder::class)
                ->whereIn('subject_id', $workOrderIds);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    /**
     * Flatten a ledger row for the table. Audience/channel fall back to the
     * description encoding so a row missing properties still renders.
     *
     * @return array<string, mixed>
     */
    private function presentRow(Activity $activity): array
    {
        $properties = $activity->properties ?? collect();
        [$audience, $channel] = array_pad(explode(':', (string) $activity->description, 2), 2, null);

        return [
            'id' => $activity->id,
            'created_at' => $activity->created_at?->toIso8601String(),
            'audience' => $properties->get('audience', $audience),
            'channel' => $properties->get('channel', $channel),
            'automation' => $activity->event,
            'automation_label' => AutomatedMessageLogService::AUTOMATIONS[$activity->event] ?? $activity->event,
            'recipient' => $properties->get('recipient'),
            'work_order_id' => $properties->get('work_order_id'),
            'work_order_no' => $properties->get('work_order_no'),
            'jobber_id' => $properties->get('jobber_id'),
            'job_number' => $properties->get('job_number'),
            'message' => $properties->get('message') ?? $properties->get('subject'),
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }
}
