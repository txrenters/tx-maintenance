<?php

namespace App\Console\Commands;

use App\Models\Attachments;
use App\Models\WorkOrderDocuments;
use Illuminate\Console\Command;

class DedupeWorkOrderDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'work-order-documents:dedupe {--dry-run : Report duplicates without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate work order documents: collapse repeated file names per work order (keeping the oldest) and drop documents matching attachments this app uploaded to PropertyWare.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $collapsedDuplicateNames = $this->collapseDuplicateFileNames($dryRun);
        $removedUploadMatches = $this->removeDocumentsMatchingUploads($dryRun);
        $this->reportUnmatchedImageDuplicates();

        $verb = $dryRun ? 'Would remove' : 'Removed';
        $this->info("{$verb} {$collapsedDuplicateNames} duplicate-named document(s).");
        $this->info("{$verb} {$removedUploadMatches} document(s) matching our own uploads.");

        return self::SUCCESS;
    }

    /**
     * Within each work order, keep the oldest document for any given file name and
     * delete the rest. This collapses PropertyWare's repeated system files such as
     * multiple "Work Order Information.pdf".
     */
    private function collapseDuplicateFileNames(bool $dryRun): int
    {
        $removed = 0;

        $duplicateGroups = WorkOrderDocuments::query()
            ->selectRaw('work_order_id, file_name, COUNT(*) as total')
            ->whereNotNull('file_name')
            ->groupBy('work_order_id', 'file_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $idsToRemove = WorkOrderDocuments::query()
                ->where('work_order_id', $group->work_order_id)
                ->where('file_name', $group->file_name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1) // keep the oldest, remove the rest
                ->values();

            $removed += $idsToRemove->count();

            if (! $dryRun && $idsToRemove->isNotEmpty()) {
                WorkOrderDocuments::whereIn('id', $idsToRemove)->delete();
            }
        }

        return $removed;
    }

    /**
     * Delete documents whose file name matches an attachment this app uploaded to
     * PropertyWare for the same work order (a re-pulled upload).
     */
    private function removeDocumentsMatchingUploads(bool $dryRun): int
    {
        $uploaded = Attachments::withoutGlobalScopes()
            ->whereNotNull('pw_file_name')
            ->get(['work_order_id', 'pw_file_name']);

        $removed = 0;

        foreach ($uploaded->groupBy('work_order_id') as $workOrderId => $rows) {
            $names = $rows->pluck('pw_file_name')->unique()->all();

            $query = WorkOrderDocuments::query()
                ->where('work_order_id', $workOrderId)
                ->whereIn('file_name', $names);

            $removed += (clone $query)->count();

            if (! $dryRun) {
                $query->delete();
            }
        }

        return $removed;
    }

    /**
     * Surface image documents that are likely round-trip duplicates of before/after
     * pictures but cannot be matched by name (uploaded before pw_file_name was
     * recorded). These need a human to review — we never delete them automatically.
     */
    private function reportUnmatchedImageDuplicates(): void
    {
        $workOrderIdsWithPictures = Attachments::withoutGlobalScopes()
            ->whereIn('type', ['before', 'after'])
            ->distinct()
            ->pluck('work_order_id');

        if ($workOrderIdsWithPictures->isEmpty()) {
            return;
        }

        $imageDocCount = WorkOrderDocuments::query()
            ->whereIn('work_order_id', $workOrderIdsWithPictures)
            ->where('file_type', 'like', 'image/%')
            ->count();

        if ($imageDocCount > 0) {
            $this->warn("{$imageDocCount} image document(s) exist on work orders that also have before/after pictures. These may be pre-existing round-trip duplicates that can't be matched by name; review them manually.");
        }
    }
}
