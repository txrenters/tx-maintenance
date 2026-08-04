<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\JobberTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin IT tools page for the Jobber integration: connection health, the
 * reconnect flow, read-only diagnostics, and the queue of THMP job creations
 * that failed while the connection was down (retry or dismiss each one).
 */
class ItToolsController extends Controller
{
    public function __construct(private JobberTokenService $tokens) {}

    public function jobber(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('ItTools/Jobber', [
            'title' => 'IT Tools — Jobber',
            'status' => $this->tokens->status(),
            'failedJobs' => $this->failedJobberJobs(),
        ]);
    }

    public function retryFailedJob(Request $request, string $uuid): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->findFailedJobberJobOrAbort($uuid);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Job queued for retry. It will run on the next queue cycle.');
    }

    public function forgetFailedJob(Request $request, string $uuid): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->findFailedJobberJobOrAbort($uuid);

        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('success', 'Failed job dismissed.');
    }

    /**
     * Failed CreateJobberJobForWorkOrder queue entries, newest first, with the
     * work order resolved so staff can cross-check Jobber for a manually
     * created job before retrying.
     *
     * @return array<int, array<string, mixed>>
     */
    private function failedJobberJobs(): array
    {
        $rows = DB::table('failed_jobs')
            ->where('payload', 'like', '%CreateJobberJobForWorkOrder%')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get();

        $workOrderIds = $rows
            ->map(fn ($row) => $this->workOrderIdFromPayload((string) $row->payload))
            ->filter()
            ->unique()
            ->values();

        $workOrders = WorkOrder::withoutGlobalScopes()
            ->whereIn('id', $workOrderIds)
            ->get(['id', 'work_order_no', 'jobber_job_gid'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($workOrders) {
            $workOrderId = $this->workOrderIdFromPayload((string) $row->payload);
            $workOrder = $workOrderId ? $workOrders->get($workOrderId) : null;

            return [
                'uuid' => $row->uuid,
                'failed_at' => $row->failed_at,
                'error' => Str::limit(strtok((string) $row->exception, "\n"), 200),
                'work_order_id' => $workOrderId,
                'work_order_no' => $workOrder?->work_order_no,
                // Linked after a retry (or by hand) — such rows are safe to dismiss.
                'already_linked' => filled($workOrder?->jobber_job_gid),
            ];
        })->values()->all();
    }

    private function workOrderIdFromPayload(string $payload): ?int
    {
        // The job object is PHP-serialized inside the JSON payload (where the
        // quotes are JSON-escaped); its only property is workOrderId.
        preg_match('/workOrderId\\\\?";i:(\d+)/', $payload, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    private function findFailedJobberJobOrAbort(string $uuid): void
    {
        $exists = DB::table('failed_jobs')
            ->where('uuid', $uuid)
            ->where('payload', 'like', '%CreateJobberJobForWorkOrder%')
            ->exists();

        abort_unless($exists, 404);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);
    }
}
