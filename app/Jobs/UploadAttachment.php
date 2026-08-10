<?php

namespace App\Jobs;

use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Throwable;

/**
 * Mirror a work order attachment (before/after photos and files) to the
 * PropertyWare work order's documents.
 *
 * pw_file_name on the attachment row stays null until PropertyWare confirms
 * the upload; the document sync uses that name to skip re-importing our own
 * upload, so writing it early would permanently mask a failed push. A false
 * from the service is converted into a throw so $tries/$backoff actually arm —
 * the old inline call swallowed every failure and could never retry.
 */
class UploadAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $attachmentId;

    /**
     * Frozen at dispatch time so every retry uploads under the same
     * PropertyWare filename — a per-attempt timestamp would strand a partial
     * success under a name the app cannot match.
     */
    public string $pwFileName;

    public function __construct(Attachments $attachment)
    {
        $this->attachmentId = $attachment->id;
        $this->pwFileName = self::propertyWareFileName($attachment);
    }

    /**
     * The PropertyWare filename for an attachment, deterministic from row data
     * alone: the repair sweep recomputes it to check whether the upload already
     * landed, and the embedded id keeps same-title bulk uploads distinct.
     */
    public static function propertyWareFileName(Attachments $attachment): string
    {
        $cleaned = str_replace(' ', '_', $attachment->title ?? '');
        $cleaned = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $cleaned);
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleaned);

        if ($sanitized === '' || $sanitized === null) {
            $sanitized = 'Attachment';
        }

        return $sanitized.'_'.$attachment->id.'_'.$attachment->created_at->format('Ymd')
            .'.'.pathinfo($attachment->filename, PATHINFO_EXTENSION);
    }

    /**
     * Execute the job.
     */
    public function handle(PropertyWareService $propertyWare): void
    {
        $attachment = Attachments::withoutGlobalScopes()->find($this->attachmentId);

        if (! $attachment) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()->find($attachment->work_order_id);

        if (! $workOrder?->propertyware_id) {
            // Not retryable here, but the daily repair sweep picks the row up
            // if the work order gets linked to PropertyWare later.
            Log::warning('Attachment upload skipped: work order has no PropertyWare id.', [
                'attachment_id' => $this->attachmentId,
                'work_order_id' => $attachment->work_order_id,
            ]);

            return;
        }

        if (! Storage::disk('public')->exists($attachment->filename)) {
            // Retrying cannot conjure the file back; fail straight to failed_jobs.
            $this->fail(new Exception('Attachment file missing from public disk: '.$attachment->filename));

            return;
        }

        // Optimize image before uploading to PropertyWare (huge win for iPhone photos)
        $this->optimizeImage($attachment->filename);

        $uploaded = $propertyWare->uploadVendorAttachment($attachment->work_order_id, [
            'title' => $attachment->title,
            'type' => $attachment->type,
            'filename' => $attachment->filename,
            'filetype' => $attachment->filetype,
            'is_publish_to_tenant_portal' => (bool) $attachment->is_publish_to_tenant_portal,
            // Before/after repair photos are always published to the PropertyWare
            // owner portal — the owner is who the photos are taken for. Plain
            // attachments keep following the upload dialog's checkbox. The local
            // row is untouched; only the PropertyWare copy is affected.
            'is_publish_to_owner_portal' => in_array($attachment->type, ['before', 'after'], true)
                ? true
                : (bool) $attachment->is_publish_to_owner_portal,
        ], $this->pwFileName);

        if ($uploaded === false || $uploaded === '') {
            // The service logs the response and returns false; throwing is what
            // arms $tries/$backoff — the old inline call could never retry.
            throw new Exception('PropertyWare rejected the attachment upload for attachment '.$this->attachmentId);
        }

        // Record the exact PropertyWare filename so the document pull can skip
        // re-importing this upload as a duplicate work order document.
        $attachment->forceFill(['pw_file_name' => $this->pwFileName])->save();
    }

    /**
     * All retries exhausted: PropertyWare has no copy of the attachment. The
     * row stays in failed_jobs for a manual retry, and the daily
     * attachments:repair-pw-uploads sweep re-dispatches it on its own.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Attachment upload to PropertyWare permanently failed.', [
            'attachment_id' => $this->attachmentId,
            'pw_file_name' => $this->pwFileName,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Optimize image files to reduce file size before uploading
     */
    protected function optimizeImage(string $filename): void
    {
        $absolutePath = Storage::disk('public')->path($filename);

        if (! file_exists($absolutePath)) {
            return;
        }

        $mimeType = mime_content_type($absolutePath);
        $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        if (! $isImage) {
            return; // Skip non-image files
        }

        try {
            $originalSize = filesize($absolutePath);

            $optimizerChain = OptimizerChainFactory::create();
            $optimizerChain->optimize($absolutePath);

            $newSize = filesize($absolutePath);
            $savedBytes = $originalSize - $newSize;
            $savedPercent = $originalSize > 0 ? round(($savedBytes / $originalSize) * 100, 2) : 0;

            Log::info('Image optimized successfully', [
                'filename' => $filename,
                'original_size' => round($originalSize / 1024, 2).'KB',
                'new_size' => round($newSize / 1024, 2).'KB',
                'saved' => round($savedBytes / 1024, 2).'KB',
                'saved_percent' => $savedPercent.'%',
            ]);
        } catch (Exception $e) {
            Log::warning('Image optimization failed, proceeding with original file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            // Continue with original file if optimization fails
        }
    }
}
