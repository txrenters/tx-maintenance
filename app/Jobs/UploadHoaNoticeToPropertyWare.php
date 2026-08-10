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

/**
 * Push an uploaded HOA violation notice PDF to the PropertyWare work order's
 * documents. Replaces the old inline, fire-and-forget upload inside
 * HoaViolationIntakeService::attachNotice(), whose failures were silently
 * swallowed while intake still reported success.
 *
 * pw_file_name on the attachment row stays null until PropertyWare confirms
 * the upload; the document sync uses that name to skip re-importing our own
 * upload, so writing it early would permanently mask a failed push.
 */
class UploadHoaNoticeToPropertyWare implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * The PropertyWare filename is frozen at dispatch time because it embeds
     * the upload date — a retry that crosses midnight must not drift.
     */
    public function __construct(
        public int $attachmentId,
        public string $pwFileName,
    ) {}

    public function handle(PropertyWareService $propertyWare): void
    {
        $attachment = Attachments::withoutGlobalScopes()->find($this->attachmentId);

        if (! $attachment) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()->find($attachment->work_order_id);

        if (! $workOrder?->propertyware_id) {
            Log::warning('HOA notice upload skipped: work order has no PropertyWare id.', [
                'attachment_id' => $this->attachmentId,
                'work_order_id' => $attachment->work_order_id,
            ]);

            return;
        }

        if (! Storage::disk('public')->exists($attachment->filename)) {
            // Retrying cannot conjure the file back; fail straight to failed_jobs.
            $this->fail(new Exception('HOA notice file missing from public disk: '.$attachment->filename));

            return;
        }

        $docId = $propertyWare->uploadWorkOrderPdf(
            (string) $workOrder->propertyware_id,
            Storage::disk('public')->get($attachment->filename),
            $this->pwFileName,
            'HOA violation notice uploaded via the maintenance app.'
        );

        if ($docId === false) {
            // uploadWorkOrderPdf logs the response and returns false; throwing is
            // what arms $tries/$backoff, unlike the old inline call.
            throw new Exception('PropertyWare rejected the HOA notice upload for attachment '.$this->attachmentId);
        }

        $attachment->forceFill(['pw_file_name' => $this->pwFileName])->save();
    }

    /**
     * All retries exhausted: PropertyWare has no copy of the notice and nothing
     * will try again on its own. The row stays in failed_jobs for a manual
     * retry; hoa:repair-notice-uploads can also re-dispatch it later.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('HOA notice upload to PropertyWare permanently failed.', [
            'attachment_id' => $this->attachmentId,
            'pw_file_name' => $this->pwFileName,
            'error' => $exception->getMessage(),
        ]);
    }
}
