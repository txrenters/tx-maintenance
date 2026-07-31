<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerServiceRequestNotificationService
{
    public function __construct(private OwnerPortalLinkService $portalLinks) {}

    /**
     * Notify every property owner on the work order that a new service request
     * has come in.
     *
     * Sends two texts from (and logged as) the WOC on the owner<->WOC
     * conversation thread: a confirmation that points the owner at the emailed
     * copy and their owner portal, followed by the request description. This is
     * a one-way notification — any reply lands in the same thread for a
     * coordinator to handle.
     *
     * Gated off by default, fired at most once per work order, and wrapped so a
     * failure is logged but never breaks intake.
     */
    public function notify(WorkOrder $workOrder): void
    {
        if (! config('services.twilio.owner_service_request_sms')) {
            return;
        }

        // A WOC can mute this work order's owner automation from the owner
        // conversation tab; manual sends are unaffected.
        if ($workOrder->automationPausedFor('owner')) {
            return;
        }

        try {
            $this->send($workOrder);
        } catch (\Throwable $exception) {
            Log::error('Owner service-request notification failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WorkOrder $workOrder): void
    {
        // HOA violations are not service requests. The generic confirmation plus
        // a raw dump of the notice's items and remedies reads to the owner as a
        // repair request, so skip the intake notification entirely and leave the
        // HOA flow to message them.
        if ($workOrder->isHoaViolation()) {
            return;
        }

        $workOrder->loadMissing([
            'owners',
            'requested_by',
            'woc.wocNumber.twilioPhoneNumber',
            'building',
        ]);

        // Every owner on the work order, any ownership percentage (0% owners
        // are usually spouses or the humans behind a phoneless LLC), shared
        // numbers de-duplicated.
        $owners = $workOrder->notifiableOwners();
        $fromNumber = $this->fromNumber($workOrder);

        // No usable numbers: nothing to text (mirrors the live vendor-assignment
        // owner notification, which also skips silently).
        if ($owners->isEmpty() || blank($fromNumber)) {
            return;
        }

        // Claim this work order atomically so a redelivery can never text the
        // owners twice for the same request.
        $claimed = DB::table('work_orders')
            ->where('id', $workOrder->id)
            ->whereNull('owner_service_request_notified_at')
            ->update(['owner_service_request_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $address = $workOrder->propertyAddress() ?? 'your property';
        $description = $this->descriptionMessage($workOrder);

        foreach ($owners as $owner) {
            $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

            if ($ownerNumber === null) {
                continue;
            }

            // Each owner gets their own no-login portal link for this request.
            $confirmation = OwnerMessageFormatter::compose(
                $this->confirmationMessage($workOrder, $address),
                $workOrder->work_order_no,
                $this->portalLinks->link($workOrder, $owner),
            );

            $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $confirmation);

            if ($description !== null) {
                $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $description);
            }
        }
    }

    /**
     * Persist one message to the owner thread (as the WOC) and queue the text.
     */
    private function post(WorkOrder $workOrder, Owner $owner, string $ownerNumber, string $fromNumber, string $message): void
    {
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

        SendConversationMessageJob::dispatch($ownerNumber, $fromNumber, $message, null, $conversation->id);
    }

    /**
     * The confirmation message: Chana's approved wording with the work order
     * number and the property address filled in.
     */
    private function confirmationMessage(WorkOrder $workOrder, string $address): string
    {
        $ref = $workOrder->work_order_no;

        // "your property" already stands in for an unknown address, so only add
        // the "at ..." clause when we have a real one.
        $property = $address === 'your property' ? 'your property' : "your property at {$address}";

        // No mention of the PropertyWare email or portal: that email is sent by
        // PropertyWare, not by us, so we cannot confirm the owner received it,
        // and the no-login link below is now the place to read the request.
        return OwnerMessageFormatter::paragraphs([
            'Hello,',
            "TexasRenters.com has received a new service request for {$property} (request #{$ref}).",
            'We will take care of arranging the estimate and any repairs needed, as outlined in your property management agreement.',
        ]);
    }

    /**
     * The follow-up message carrying the request description, or null when the
     * work order has no description on file.
     */
    private function descriptionMessage(WorkOrder $workOrder): ?string
    {
        $description = trim((string) $workOrder->description);

        if ($description === '') {
            return null;
        }

        return OwnerMessageFormatter::compose(
            OwnerMessageFormatter::paragraphs([
                'Here are the details of the request, for your reference:',
                $description,
            ]),
            $workOrder->work_order_no,
        );
    }

    /**
     * The WOC's own number if the work order has one, else the maintenance line.
     */
    private function fromNumber(WorkOrder $workOrder): ?string
    {
        return $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));
    }
}
