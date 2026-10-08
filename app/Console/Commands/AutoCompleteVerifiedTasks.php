<?php

namespace App\Console\Commands;

use App\Models\Attachments;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-tick checklist tasks the database already proves done: pure data-entry
 * confirmations ("Fill in scheduled date", "Confirm the invoice has been
 * uploaded", ...) whose condition is verifiably true, so coordinators stop
 * re-confirming facts the system already knows.
 *
 * Cascade safety is the whole design: a completed task can normally advance
 * the work order's service status, regenerate its checklist and push to
 * PropertyWare. This command must never do any of that, so it (a) only touches
 * templates whose next service status resolves to "Not Changed" — checked
 * against the template ROW at runtime, never by task name, because the
 * Turnover seeder re-creates same-named templates with different cascade
 * wiring — (b) skips Yes/No (is_optional) tasks whose transition depends on
 * the answer, and (c) completes via a bare query update, the same
 * deliberately cascade-free pattern as the web TaskController::bulkComplete.
 */
class AutoCompleteVerifiedTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:auto-complete
        {--dry-run : Report what would be completed without writing anything}
        {--limit=500 : Maximum tasks to complete per run}
        {--work-order= : Only consider tasks on this work order number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Complete pending checklist tasks whose data-entry condition is already verifiably true (category/zone/plan filled, vendor assigned, schedule set, photos or invoice uploaded, photos synced/published). Never advances the service status. Scheduled every 30 minutes; safe to re-run.';

    /**
     * Template name => condition key. Names identify candidates only; the
     * runtime "Not Changed" + not-optional gates on the template row decide
     * whether a task is actually safe to complete.
     *
     * @var array<string, string>
     */
    private const CONDITIONS = [
        'Update Category to Match the type of repair requested' => 'category',
        'Update Zone for Service Request (Look at Property)' => 'zone',
        'Update Management Plan (Important for Charges)' => 'management_plan',
        'Assign to Appropriate Vendor' => 'vendor_assigned',
        'Fill in scheduled date' => 'scheduled_date',
        'Fill in Scheduled Start Date' => 'scheduled_date',
        'Upload "before pictures of problem"' => 'before_photos',
        'Upload before pictures of the problem' => 'before_photos',
        'After Completing Service - take "After Photos in App"' => 'after_photos',
        'Take a photo of work completed add description in photo name' => 'after_photos',
        'Have Before and After Photos been Uploaded?' => 'before_and_after_photos',
        'Upload Invoice to app for payment' => 'invoice_uploaded',
        'Confirm the invoice has been uploaded' => 'invoice_uploaded',
        'Confirm that All Pictures have synced to PW' => 'pictures_synced',
        'Publish all Photos in PW to Owner and Tenant' => 'photos_published',
    ];

    /**
     * Condition key => human reason shown in the output and the log.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        'category' => 'category is filled in',
        'zone' => 'zone is filled in',
        'management_plan' => 'management plan is filled in',
        'vendor_assigned' => 'a vendor is assigned',
        'scheduled_date' => 'a scheduled date is on record',
        'before_photos' => 'before photo(s) are uploaded',
        'after_photos' => 'after photo(s) are uploaded',
        'before_and_after_photos' => 'before and after photos are uploaded',
        'invoice_uploaded' => 'an invoice is uploaded',
        'pictures_synced' => 'every before/after photo has synced to PropertyWare',
        'photos_published' => 'every before/after photo is published to both portals',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $workOrderNo = $this->option('work-order');

        $targetWorkOrder = null;

        if ($workOrderNo !== null) {
            $targetWorkOrder = WorkOrder::withoutGlobalScopes()
                ->where('work_order_no', $workOrderNo)
                ->first();

            if (! $targetWorkOrder) {
                $this->error("No work order found with number {$workOrderNo}.");

                return self::FAILURE;
            }
        }

        $candidates = WorkOrderTask::withoutGlobalScopes()
            ->with('task')
            ->where('status', 'pending')
            // A task a person un-ticked keeps its stamp: never tick it again.
            ->whereNull('auto_completed_at')
            ->whereNotNull('task_id')
            ->when($targetWorkOrder, fn ($query) => $query->where('work_order_id', $targetWorkOrder->id))
            ->whereHas('task', function ($template) {
                $template->whereIn('name', array_keys(self::CONDITIONS))
                    ->where('is_optional', false)
                    ->whereHas('nextServiceStatus', fn ($status) => $status->where('name', 'Not Changed'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No auto-completable pending tasks found.');

            return self::SUCCESS;
        }

        $completed = [];

        foreach ($candidates->groupBy('work_order_id') as $workOrderId => $tasks) {
            $workOrder = WorkOrder::withoutGlobalScopes()->find($workOrderId);

            if (! $workOrder || $workOrder->status === 'Closed') {
                continue;
            }

            $label = 'WO#'.($workOrder->work_order_no ?? $workOrder->id);
            $verified = [];

            foreach ($tasks as $task) {
                $condition = self::CONDITIONS[$task->task->name] ?? null;

                if ($condition === null || ! ($verified[$condition] ??= $this->conditionMet($condition, $workOrder))) {
                    continue;
                }

                $reason = self::REASONS[$condition];
                $verb = $dryRun ? 'would complete' : 'completed';
                $this->line("{$label}: {$verb} \"{$task->task->name}\" — {$reason}.");

                $completed[] = ['id' => $task->id, 'work_order_id' => $workOrderId, 'name' => $task->task->name, 'reason' => $reason];
            }
        }

        if (! $dryRun && $completed !== []) {
            // Stamped per reason so the task's "Auto" badge can say why; the
            // stamp also keeps a later un-tick by a person from being undone.
            foreach (collect($completed)->groupBy('reason') as $reason => $group) {
                WorkOrderTask::withoutGlobalScopes()
                    ->whereIn('id', $group->pluck('id'))
                    ->update([
                        'status' => 'completed',
                        'auto_completed_at' => now(),
                        'auto_complete_reason' => $reason,
                    ]);
            }

            // No completed_by column exists on work_order_tasks, so the log is
            // the attribution trail for these system completions.
            Log::info('Auto-completed verified checklist tasks.', ['tasks' => $completed]);
        }

        $verb = $dryRun ? 'Would complete' : 'Completed';
        $this->info("{$verb} ".count($completed).' task(s) across '.count(array_unique(array_column($completed, 'work_order_id'))).' work order(s).');

        return self::SUCCESS;
    }

    /**
     * Whether the data the task asks a human to confirm is already present.
     */
    private function conditionMet(string $condition, WorkOrder $workOrder): bool
    {
        return match ($condition) {
            'category' => trim((string) $workOrder->category) !== '',
            'zone' => trim((string) $workOrder->zone) !== '',
            'management_plan' => trim((string) $workOrder->management_plan) !== '',
            'vendor_assigned' => DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->exists(),
            'scheduled_date' => $workOrder->start_date !== null
                || $workOrder->service_schedules()->exists(),
            'before_photos' => $this->photos($workOrder, ['before'])->exists(),
            'after_photos' => $this->photos($workOrder, ['after'])->exists(),
            'before_and_after_photos' => $this->photos($workOrder, ['before'])->exists()
                && $this->photos($workOrder, ['after'])->exists(),
            'invoice_uploaded' => Invoice::where('work_order_id', $workOrder->id)->exists(),
            'pictures_synced' => $this->photos($workOrder, ['before', 'after'])->exists()
                && ! $this->photos($workOrder, ['before', 'after'])->whereNull('pw_file_name')->exists(),
            'photos_published' => $this->photos($workOrder, ['before', 'after'])->exists()
                && ! $this->photos($workOrder, ['before', 'after'])
                    ->where(fn ($query) => $query
                        ->where('is_publish_to_owner_portal', '!=', 1)
                        ->orWhereNull('is_publish_to_owner_portal')
                        ->orWhere('is_publish_to_tenant_portal', '!=', 1)
                        ->orWhereNull('is_publish_to_tenant_portal'))
                    ->exists(),
            default => false,
        };
    }

    private function photos(WorkOrder $workOrder, array $types)
    {
        return Attachments::withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->whereIn('type', $types);
    }
}
