<?php

namespace App\Console\Commands;

use App\Models\Attachments;
use App\Models\WorkOrderDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Remove duplicate work order documents: collapse repeated file names per work order (keeping the app-written copy, else the oldest), drop documents matching attachments this app uploaded to PropertyWare, and remove PropertyWare thumbnails (THMP_). Scheduled nightly; safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $collapsedDuplicateNames = $this->collapseDuplicateFileNames($dryRun);
        $removedUploadMatches = $this->removeDocumentsMatchingUploads($dryRun);
        $removedThumbnails = $this->removeThumbnailDocuments($dryRun);
        $this->reportUnmatchedImageDuplicates();

        $verb = $dryRun ? 'Would remove' : 'Removed';
        $this->info("{$verb} {$collapsedDuplicateNames} duplicate-named document(s).");
        $this->info("{$verb} {$removedUploadMatches} document(s) matching our own uploads.");
        $this->info("{$verb} {$removedThumbnails} PropertyWare thumbnail(s).");

        return self::SUCCESS;
    }

    /**
     * Within each work order, keep one document for any given file name and
     * delete the rest. This collapses PropertyWare's repeated system files such
     * as multiple "Work Order Information.pdf". The copy this app wrote (its
     * download link is the one staff use) is preferred as the keeper; with no
     * app-written copy the oldest survives.
     */
    private function collapseDuplicateFileNames(bool $dryRun): int
    {
        $removed = 0;
        $appUser = (string) config('services.propertyware.username');

        $duplicateGroups = WorkOrderDocuments::query()
            ->selectRaw('work_order_id, file_name, COUNT(*) as total')
            ->whereNotNull('file_name')
            ->groupBy('work_order_id', 'file_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $documents = WorkOrderDocuments::query()
                ->where('work_order_id', $group->work_order_id)
                ->where('file_name', $group->file_name)
                ->orderBy('id')
                ->get(['id', 'work_order_id', 'file_name', 'propertyware_id', 'created_by_id']);

            $keeper = ($appUser !== '' ? $documents->firstWhere('created_by_id', $appUser) : null)
                ?? $documents->first();

            $toRemove = $documents->where('id', '!=', $keeper->id)->values();

            $removed += $toRemove->count();

            if (! $dryRun && $toRemove->isNotEmpty()) {
                $this->logRemovals('duplicate-named', $toRemove);
                WorkOrderDocuments::whereIn('id', $toRemove->pluck('id'))->delete();
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

            $matches = WorkOrderDocuments::query()
                ->where('work_order_id', $workOrderId)
                ->whereIn('file_name', $names)
                ->get(['id', 'work_order_id', 'file_name', 'propertyware_id']);

            $removed += $matches->count();

            if (! $dryRun && $matches->isNotEmpty()) {
                $this->logRemovals('upload-matching', $matches);
                WorkOrderDocuments::whereIn('id', $matches->pluck('id'))->delete();
            }
        }

        return $removed;
    }

    /**
     * Delete PropertyWare's auto-generated thumbnail documents (THMP_ prefix).
     * These are previews of a document we already sync, so they only duplicate
     * the attachments tab.
     */
    private function removeThumbnailDocuments(bool $dryRun): int
    {
        // Cheap, portable prefilter, then match the exact "THMP_" prefix via the
        // shared helper (avoids database-specific LIKE escaping of the underscore).
        $thumbnails = WorkOrderDocuments::query()
            ->where('file_name', 'like', 'THMP%')
            ->get(['id', 'work_order_id', 'file_name', 'propertyware_id'])
            ->filter(fn ($doc) => WorkOrderDocuments::isThumbnailFileName($doc->file_name))
            ->values();

        if (! $dryRun && $thumbnails->isNotEmpty()) {
            $this->logRemovals('thumbnail', $thumbnails);
            WorkOrderDocuments::whereIn('id', $thumbnails->pluck('id'))->delete();
        }

        return $thumbnails->count();
    }

    /**
     * Deletes on this table are permanent (no soft deletes), so a scheduled run
     * keeps an audit trail of exactly which rows it removed and why.
     *
     * @param  Collection<int, WorkOrderDocuments>  $documents
     */
    private function logRemovals(string $reason, Collection $documents): void
    {
        Log::info("Work order document dedupe removing {$reason} document(s).", [
            'documents' => $documents->map(fn (WorkOrderDocuments $document): array => [
                'id' => $document->id,
                'work_order_id' => $document->work_order_id,
                'file_name' => $document->file_name,
                'propertyware_id' => $document->propertyware_id,
            ])->all(),
        ]);
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
