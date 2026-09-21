<?php

namespace App\Console\Commands;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Self-healing backstop for the photo/attachment mirror to PropertyWare: any
 * attachment whose queued upload never confirmed (pw_file_name still null)
 * gets re-dispatched, so a silently lost photo cannot stay lost past a day.
 *
 * The --days window exists because rows created before the pw_file_name column
 * (migration 2026_06_30_175438) are all null yet mostly DID land in
 * PropertyWare under old per-attempt timestamped names this command cannot
 * match — sweeping them would mass-duplicate. Backdated attachments (staff can
 * set created_at on upload) can likewise age out of the window; their original
 * queued upload still ran with full retries, only this backstop skips them.
 */
class RepairAttachmentPwUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attachments:repair-pw-uploads
        {--dry-run : Report what would be re-uploaded without dispatching or writing anything}
        {--days=14 : Only consider attachments created within this many days}
        {--limit=100 : Maximum attachments to process per run}
        {--work-order= : Only consider attachments on this work order number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-push work order attachments whose PropertyWare upload silently failed: for each unconfirmed attachment on a PropertyWare-linked work order, check whether the document actually exists in PropertyWare and re-dispatch the upload when it does not. Scheduled daily; safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(PropertyWareService $propertyWare): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
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

        $candidates = Attachments::withoutGlobalScopes()
            ->whereNull('pw_file_name')
            ->when($targetWorkOrder, fn ($query) => $query->where('work_order_id', $targetWorkOrder->id))
            // HOA notice PDFs have their own job and repair command.
            ->where(fn ($query) => $query
                ->where('title', '!=', 'HOA violation notice')
                ->orWhereNull('title'))
            // Photos read off a Jobber note are the crew's internal record and
            // have no PropertyWare copy to reconcile with. Their pw_file_name
            // is null and always will be, so without this they would every one
            // of them be swept into PropertyWare a day after arriving.
            ->whereNull('jobber_note_file_gid')
            ->where('created_at', '>=', now()->subDays($days))
            // Grace period: a just-created row may still have its upload job
            // sitting in the queue — don't dispatch a second one.
            ->where('created_at', '<=', now()->subHour())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unconfirmed attachment uploads found.');

            return self::SUCCESS;
        }

        $redispatched = 0;
        $present = 0;
        $missingFile = 0;
        $skippedLocal = 0;

        foreach ($candidates->groupBy('work_order_id') as $workOrderId => $attachments) {
            $workOrder = WorkOrder::withoutGlobalScopes()->find($workOrderId);

            if (! $workOrder?->propertyware_id) {
                $skippedLocal += $attachments->count();

                continue;
            }

            // An empty listing can also mean the listing call itself failed (the
            // service logs and returns []); worst case a re-run uploads a copy
            // PropertyWare will show twice, which staff can delete — preferable
            // to a missing photo.
            $pwNames = collect($propertyWare->getWorkOrderDocuments($workOrder->propertyware_id))
                ->pluck('fileName')
                ->filter();

            foreach ($attachments as $attachment) {
                $label = 'WO#'.($workOrder->work_order_no ?? $workOrder->id)." (attachment {$attachment->id})";
                $expected = UploadAttachment::propertyWareFileName($attachment);

                if ($pwNames->contains($expected)) {
                    // The upload landed but the confirmation save never did —
                    // backfill so the document sync skips it and this sweep
                    // stops re-checking it.
                    if (! $dryRun) {
                        $attachment->forceFill(['pw_file_name' => $expected])->save();
                    }

                    $present++;

                    continue;
                }

                if (! Storage::disk('public')->exists($attachment->filename)) {
                    $missingFile++;
                    $this->warn("{$label}: file missing on disk — re-upload it manually through the work order.");

                    continue;
                }

                if ($dryRun) {
                    $this->line("{$label}: would re-upload as \"{$expected}\".");
                } else {
                    // The job recomputes the identical deterministic name.
                    UploadAttachment::dispatch($attachment);
                    $this->line("{$label}: re-upload dispatched as \"{$expected}\".");
                }

                $redispatched++;
            }
        }

        $verb = $dryRun ? 'Would re-upload' : 'Re-upload dispatched for';
        $this->info("{$verb} {$redispatched} attachment(s). Already in PropertyWare: {$present}. Missing file on disk: {$missingFile}. Local-only work orders skipped: {$skippedLocal}.");

        return self::SUCCESS;
    }
}
