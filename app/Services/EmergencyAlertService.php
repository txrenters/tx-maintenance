<?php

namespace App\Services;

use App\Models\WOCNumbers;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class EmergencyAlertService
{
    /**
     * Alert staff that a work order has been classified as an emergency:
     * an in-app notification (activity log, visible to all staff through
     * the notification bell) and an SMS to the work order's assigned WOC.
     *
     * Fires at most once per work order, so a re-classification or a staff
     * confirmation after an AI alert never duplicates the notification.
     *
     * @param  string  $classifiedBy  'ai' or 'staff'
     */
    public function workOrderMarkedEmergency(WorkOrder $workOrder, string $classifiedBy): void
    {
        if ($this->alreadyAlerted($workOrder)) {
            return;
        }

        activity()
            ->performedOn($workOrder)
            ->event('work_order_emergency')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'message' => Str::limit((string) $workOrder->description, 140) ?: 'No description provided.',
                'classified_by' => $classifiedBy,
                'read' => false,
            ])
            ->log('EMERGENCY - Work Order #'.$workOrder->work_order_no);

        $this->sendWocSms($workOrder, $classifiedBy);
    }

    private function alreadyAlerted(WorkOrder $workOrder): bool
    {
        return Activity::query()
            ->where('event', 'work_order_emergency')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->exists();
    }

    /**
     * Text the assigned WOC from their own Twilio number. Gated behind
     * EMERGENCY_SMS_ENABLED so local and test environments never text real
     * people, and never allowed to break the classification flow.
     */
    private function sendWocSms(WorkOrder $workOrder, string $classifiedBy): void
    {
        if (! config('services.twilio.emergency_sms')) {
            return;
        }

        $woc = $workOrder->woc;

        if (! $woc || blank($woc->phone)) {
            Log::info('Emergency SMS skipped: work order has no WOC with a phone number.', [
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
            Log::warning('Emergency SMS skipped: WOC has no Twilio number mapped in woc_numbers.', [
                'work_order_id' => $workOrder->id,
                'woc_user_id' => $woc->id,
            ]);

            return;
        }

        $message = implode(' ', array_filter([
            'EMERGENCY work order #'.$workOrder->work_order_no.':',
            Str::limit((string) $workOrder->description, 200),
            $workOrder->location ? '('.$workOrder->location.')' : null,
            'Classified by '.($classifiedBy === 'ai' ? 'AI' : 'staff').'. Please respond as soon as possible.',
        ]));

        try {
            app(TwilioService::class)->sendMessage($woc->phone, $fromNumber, $message);
        } catch (\Throwable $exception) {
            Log::error('Emergency SMS failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
