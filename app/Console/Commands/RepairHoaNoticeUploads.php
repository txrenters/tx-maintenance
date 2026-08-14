<?php

namespace App\Console\Commands;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RepairHoaNoticeUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoa:repair-notice-uploads
        {--dry-run : Report what would be re-uploaded without dispatching anything}
        {--days=14 : Only consider notices created within this many days}
        {--limit=100 : Maximum notices to process per run}
        {--work-order= : Only consider notices on this work order number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-push HOA violation notices whose PropertyWare upload silently failed: for each HOA notice attachment on a PropertyWare-linked work order, check whether the document actually exists in PropertyWare and re-dispatch the upload when it does not. Scheduled daily; safe to re-run.';

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

        // Every notice type the upload dialog accepts, not just PDFs: a
        // photographed notice reaches PropertyWare the same way, and the
        // attachment sweep skips this title, so anything left out here is
        // covered by neither command.
        //
        // The window and limit keep the nightly run's PropertyWare traffic flat
        // as notices accumulate — every candidate costs a document listing call.
        // Reach further back on a manual run with --days=, and --work-order
        // ignores the window so a named work order is always checked.
        $notices = Attachments::withoutGlobalScopes()
            ->where('title', 'HOA violation notice')
            ->when(
                $targetWorkOrder,
                fn ($query) => $query->where('work_order_id', $targetWorkOrder->id),
                fn ($query) => $query->where('created_at', '>=', now()->subDays($days)),
            )
            // Grace period: a just-created notice may still have its upload job
            // sitting in the queue — don't dispatch a second one.
            ->where('created_at', '<=', now()->subHour())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($notices->isEmpty()) {
            $this->info('No HOA notice attachments found.');

            return self::SUCCESS;
        }

        $redispatched = 0;
        $present = 0;
        $missingFile = 0;
        $skippedLocal = 0;

        foreach ($notices as $attachment) {
            $workOrder = WorkOrder::withoutGlobalScopes()->find($attachment->work_order_id);

            if (! $workOrder?->propertyware_id) {
                $skippedLocal++;

                continue;
            }

            $label = 'WO#'.($workOrder->work_order_no ?? $workOrder->id)." (attachment {$attachment->id})";

            // An empty listing can also mean the listing call itself failed (the
            // service logs and returns []); worst case a re-run uploads a copy
            // PropertyWare will show twice, which staff can delete — preferable
            // to a missing notice.
            $documents = $propertyWare->getWorkOrderDocuments($workOrder->propertyware_id);
            $pwNames = collect($documents)->pluck('fileName')->filter();

            // Present under its recorded name, or under any HOA-notice name for
            // this work order (covers rows whose queued upload already landed
            // but whose name was cleared or never confirmed).
            $noticePrefix = 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - ';
            $alreadyInPw = ($attachment->pw_file_name && $pwNames->contains($attachment->pw_file_name))
                || $pwNames->contains(fn ($name) => str_starts_with((string) $name, $noticePrefix));

            if ($alreadyInPw) {
                $present++;

                continue;
            }

            if (! Storage::disk('public')->exists($attachment->filename)) {
                $missingFile++;
                $this->warn("{$label}: notice file missing on disk — re-upload the notice manually through the HOA board.");

                continue;
            }

            // PropertyWare types the document off this extension, so take it
            // from the stored file rather than assuming a PDF — a photographed
            // notice must not land in PropertyWare named .pdf.
            $extension = pathinfo($attachment->filename, PATHINFO_EXTENSION) ?: 'pdf';

            $fileName = $attachment->pw_file_name
                ?: 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - '.now()->format('Y-m-d').'.'.$extension;

            if ($dryRun) {
                $this->line("{$label}: would re-upload as \"{$fileName}\".");
            } else {
                // Clear the eager name written by the old code so the document
                // sync cannot skip the doc if this push fails again.
                $attachment->forceFill(['pw_file_name' => null])->save();

                UploadHoaNoticeToPropertyWare::dispatch($attachment->id, $fileName);
                $this->line("{$label}: re-upload dispatched as \"{$fileName}\".");
            }

            $redispatched++;
        }

        $verb = $dryRun ? 'Would re-upload' : 'Re-upload dispatched for';
        $this->info("{$verb} {$redispatched} notice(s). Already in PropertyWare: {$present}. Missing file on disk: {$missingFile}. Local-only work orders skipped: {$skippedLocal}.");

        return self::SUCCESS;
    }
}
