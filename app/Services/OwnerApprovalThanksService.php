<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * Thanks an owner the moment they approve a work order in the owner portal.
 *
 * The approval lands on the owner thread as an inbound message, and until
 * someone answers it the Inbox lists it under Awaiting reply. Nobody needs to
 * answer an approval by hand: the business's thank-you (copy from Earl,
 * 2026-10-27) goes out from the WOC on the same thread, with the owner's
 * portal link, and like any outbound owner message it stamps the approval as
 * answered (Conversation::booted -> AnsweredNotificationReader). The bell
 * entry is left alone, so the coordinator still sees that the owner approved.
 *
 * Approvals only; a disapproval needs a human. One owner: the one who clicked,
 * never the co-owners. An owner with no phone on file gets the same message in
 * their portal (a coordinator row on their thread, nothing texted), as
 * coordinators' own replies reach them.
 */
class OwnerApprovalThanksService
{
    /**
     * The business's wording, verbatim. Plain ASCII: a single curly quote or
     * dash would push the whole text to UCS-2 and double its segments.
     */
    public const COPY = "Good day!\n\n"
        .'Thank you for your approval and for trusting us with your property. '
        .'We will do our best to ensure the work is completed professionally and in a timely manner. '
        ."Our team will coordinate the process to achieve the best possible results for your property.\n\n"
        .'Thank you.';

    public function __construct(private OwnerPortalLinkService $portalLinks) {}

    /**
     * Gated by OWNER_APPROVAL_THANKS_SMS_ENABLED and by the work order's owner
     * automation toggle; wrapped so a failure is logged but never costs the
     * owner their recorded decision.
     */
    public function thank(WorkOrder $workOrder, Owner $owner, Conversation $approval): void
    {
        if (! config('services.twilio.owner_approval_thanks_sms')) {
            return;
        }

        if ($workOrder->automationPausedFor('owner')) {
            return;
        }

        try {
            $this->send($workOrder, $owner, $approval);
        } catch (\Throwable $exception) {
            Log::error('Owner approval thank-you failed to send.', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WorkOrder $workOrder, Owner $owner, Conversation $approval): void
    {
        $workOrder->loadMissing('woc.wocNumber.twilioPhoneNumber');

        $fromNumber = $this->fromNumber($workOrder);

        // Nothing to send from: the portal renders a coordinator message by its
        // sender number, so without one there is no thread row to show either.
        if (blank($fromNumber)) {
            return;
        }

        $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

        $message = OwnerMessageFormatter::compose(
            self::COPY,
            $workOrder->work_order_no,
            $this->portalLinks->link($workOrder, $owner),
        );

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber,
            'receiver_number' => $ownerNumber,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        if ($ownerNumber !== null) {
            SendConversationMessageJob::dispatch($ownerNumber, $fromNumber, $message, null, $conversation->id);
        }

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'owner',
            'owner_approval_thanks_sms',
            $ownerNumber ?? 'portal',
            $workOrder,
            $message,
            [
                'owner_id' => $owner->id,
                'conversation_id' => $conversation->id,
                'approval_conversation_id' => $approval->id,
                'delivery' => $ownerNumber !== null ? 'sms' : 'portal',
            ],
        );
    }

    private function fromNumber(WorkOrder $workOrder): ?string
    {
        return $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));
    }
}
