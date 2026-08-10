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
    protected $signature = 'hoa:repair-notice-uploads {--dry-run : Report what would be re-uploaded without dispatching anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-push HOA violation notice PDFs whose PropertyWare upload silently failed: for each HOA notice attachment on a PropertyWare-linked work order, check whether the document actually exists in PropertyWare and re-dispatch the upload when it does not. Run manually; safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(PropertyWareService $propertyWare): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $notices = Attachments::withoutGlobalScopes()
            ->where('title', 'HOA violation notice')
            ->where('filetype', 'application/pdf')
            ->orderBy('id')
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
                $this->warn("{$label}: notice file missing on disk — re-upload the PDF manually through the HOA board.");

                continue;
            }

            $fileName = $attachment->pw_file_name
                ?: 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - '.now()->format('Y-m-d').'.pdf';

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
