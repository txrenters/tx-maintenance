<?php

namespace App\Jobs;

use App\Ai\Agents\CompletionPhotoReviewAgent;
use App\Models\AiInsight;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\AiSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;

/**
 * Asks the vision agent whether a vendor's "after" photo plausibly shows the
 * work order's reported issue addressed, and stores the verdict as a
 * read-only ai_insights row the Attachments tab renders. A failed or skipped
 * review changes nothing — the upload already succeeded and staff simply see
 * no flag, exactly as before this feature existed.
 *
 * Takes the attachment id rather than the model (same reasoning as
 * GenerateThumbnail): Attachments carries AttachmentScope, which would be
 * reapplied on re-resolution and could silently resolve to null.
 */
class ReviewCompletionPhoto implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    public $backoff = 30;

    public $timeout = 180;

    private const VERDICTS = ['looks_resolved', 'mismatch', 'unclear'];

    public function __construct(public int $attachmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    public function handle(): void
    {
        if (! config('services.ai.photo_review', true) || ! AiSettings::ready()) {
            return;
        }

        $attachment = Attachments::withoutGlobalScopes()->find($this->attachmentId);

        if (
            $attachment === null
            || $attachment->type !== 'after'
            || ! str_starts_with((string) $attachment->filetype, 'image/')
        ) {
            return;
        }

        // Judged once; re-dispatches (queue retries after deploys, manual
        // re-uploads of the same row) are no-ops.
        $alreadyReviewed = AiInsight::query()
            ->ofType(AiInsight::TYPE_PHOTO_REVIEW)
            ->where('subject_type', Attachments::class)
            ->where('subject_id', $attachment->id)
            ->exists();

        if ($alreadyReviewed) {
            return;
        }

        if (! Storage::disk('public')->exists((string) $attachment->filename)) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()->find($attachment->work_order_id);

        if ($workOrder === null) {
            return;
        }

        try {
            $response = (new CompletionPhotoReviewAgent)->prompt(
                $this->buildPrompt($workOrder, $attachment),
                [Image::fromStorage((string) $attachment->filename, 'public')],
                timeout: 120,
            );

            $verdict = strtolower(trim((string) data_get($response, 'verdict')));

            AiInsight::query()->updateOrCreate(
                [
                    'type' => AiInsight::TYPE_PHOTO_REVIEW,
                    'subject_type' => Attachments::class,
                    'subject_id' => $attachment->id,
                ],
                [
                    'work_order_id' => $workOrder->id,
                    'status' => AiInsight::STATUS_OPEN,
                    'data' => [
                        'verdict' => in_array($verdict, self::VERDICTS, true) ? $verdict : 'unclear',
                        'note' => Str::limit(trim((string) data_get($response, 'note')), 300),
                    ],
                    'generated_at' => now(),
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Completion photo review failed; leaving the photo unflagged.', [
                'attachment_id' => $attachment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function buildPrompt(WorkOrder $workOrder, Attachments $attachment): string
    {
        return implode("\n", array_filter([
            'WORK ORDER',
            'Reported issue: '.Str::limit(trim((string) $workOrder->description), 600),
            filled($workOrder->type) ? 'Type: '.$workOrder->type : null,
            filled($workOrder->category) ? 'Category: '.$workOrder->category : null,
            filled($workOrder->location) ? 'Location on property: '.$workOrder->location : null,
            '',
            'The attached image was uploaded by the vendor as an AFTER photo ("'.Str::limit(trim((string) $attachment->title), 120).'").',
            'Judge whether it plausibly shows the reported issue addressed.',
        ]));
    }
}
