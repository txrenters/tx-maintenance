<?php

namespace App\Services;

use App\Models\WOCNumbers;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class DescriptionChangeAlertService
{
    /**
     * How long a matching alert on the same work order suppresses a repeat.
     * The two PropertyWare syncs (10 and 15 minute schedules) can both read
     * the old description before either writes, so the same edit may be
     * detected twice within one overlap window.
     */
    private const DEDUPE_MINUTES = 30;

    /**
     * Snippet length stored in the activity properties. Comparison always
     * uses the full strings; only the stored copies are truncated.
     */
    private const SNIPPET_LIMIT = 500;

    /**
     * Alert staff that a work order's description was changed on the
     * PropertyWare side (typically a tenant editing their request after
     * intake): an in-app notification (activity log, visible to all staff
     * through the notification bell) and an SMS to the assigned WOC.
     *
     * Unlike the emergency alert this fires for every distinct edit — each
     * change is new information staff can miss. Cross-sync idempotency is
     * natural: whichever sync applies the new value first leaves the stored
     * and incoming descriptions equal for the other.
     *
     * Must never throw: the REST status sync runs its whole ~5000 work
     * order loop inside one try/catch, so an exception here would abort
     * every remaining work order in the run.
     *
     * @param  string  $source  'soap_import' or 'rest_status_sync'
     */
    public function detectAndAlert(WorkOrder $workOrder, ?string $oldDescription, ?string $incomingDescription, string $source): void
    {
        try {
            if (! $this->descriptionMeaningfullyChanged($oldDescription, $incomingDescription)) {
                return;
            }

            $newSnippet = Str::limit((string) $incomingDescription, self::SNIPPET_LIMIT);

            if ($this->recentlyAlerted($workOrder, $newSnippet)) {
                return;
            }

            activity()
                ->performedOn($workOrder)
                ->event('work_order_description_updated')
                ->withProperties([
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'message' => 'Description was edited in PropertyWare. New: '.Str::limit((string) $incomingDescription, 140),
                    'old_description' => Str::limit((string) $oldDescription, self::SNIPPET_LIMIT),
                    'new_description' => $newSnippet,
                    'source' => $source,
                    'read' => false,
                ])
                ->log('Description updated in PropertyWare - Work Order #'.$workOrder->work_order_no);

            $this->sendWocSms($workOrder, $incomingDescription);
        } catch (\Throwable $exception) {
            Log::error('Description change alert failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * True only for a real edit: both values non-blank and different after
     * whitespace normalization. First population of an empty description
     * never alerts, and a null/blank incoming value is never trusted — the
     * SOAP import maps an absent PropertyWare description to null.
     */
    public function descriptionMeaningfullyChanged(?string $old, ?string $incoming): bool
    {
        $normalizedOld = $this->normalize($old);
        $normalizedIncoming = $this->normalize($incoming);

        if ($normalizedOld === '' || $normalizedIncoming === '') {
            return false;
        }

        return $normalizedOld !== $normalizedIncoming;
    }

    /**
     * Collapse the cosmetic differences PropertyWare produces between its
     * SOAP and REST representations (line endings, whitespace runs) so they
     * never read as edits.
     */
    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function recentlyAlerted(WorkOrder $workOrder, string $newSnippet): bool
    {
        return Activity::query()
            ->where('event', 'work_order_description_updated')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->where('created_at', '>=', now()->subMinutes(self::DEDUPE_MINUTES))
            ->where('properties->new_description', $newSnippet)
            ->exists();
    }

    /**
     * Text the assigned WOC from their own Twilio number. Gated behind
     * DESCRIPTION_CHANGE_SMS_ENABLED (off by default) and never allowed to
     * break the sync flow.
     */
    private function sendWocSms(WorkOrder $workOrder, string $newDescription): void
    {
        if (! config('services.twilio.description_change_sms')) {
            return;
        }

        $woc = $workOrder->woc;

        if (! $woc || blank($woc->phone)) {
            Log::info('Description change SMS skipped: work order has no WOC with a phone number.', [
                'work_order_id' => $workOrder->id,
            ]);

            return;
        }

        $fromNumber = WOCNumbers::query()
            ->where('user_id', $woc->id)
            ->with('twilioPhoneNumber')
            ->first()
            ?->twilioPhoneNumber
            ?->phone_number;

        if (blank($fromNumber)) {
            Log::warning('Description change SMS skipped: WOC has no Twilio number mapped in woc_numbers.', [
                'work_order_id' => $workOrder->id,
                'woc_user_id' => $woc->id,
            ]);

            return;
        }

        $message = implode(' ', array_filter([
            'Work order #'.$workOrder->work_order_no.': the description was updated in PropertyWare.',
            'New description: '.Str::limit($newDescription, 180),
            $workOrder->location ? '('.$workOrder->location.')' : null,
            'Please review - items may have been added.',
        ]));

        try {
            app(TwilioService::class)->sendMessage($woc->phone, $fromNumber, $message);
        } catch (\Throwable $exception) {
            Log::error('Description change SMS failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
