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
     * Notify every property owner on the work order that a new work order has
     * come in.
     *
     * Sends from (and logged as) the WOC on the owner<->WOC conversation
     * thread. For a request the tenant raised (PropertyWare Source "Tenant
     * Portal" or "Website"): two texts, a confirmation that points the owner at
     * their owner portal, followed by the request description. For a work
     * order our team entered in PropertyWare: one text saying so, with the
     * description inline. Either way this is a one-way notification — any
     * reply lands in the same thread for a coordinator to handle.
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
        // A turnover/re-key/vacant or refresh-cleaning work order is not a
        // tenant service request — it is work the company already set in
        // motion (re-keys go to Express Key, cleanings are company-ordered),
        // so the "we have received a new service request" confirmation reads
        // as noise or as a request the owner never made. Skip it, leaving the
        // one-shot stamp clear so a re-categorized work order can still
        // notify. (This skip existed before, was removed by the 07-29 intake
        // reword, and is deliberately restored per the 08-06 ticket.)
        if ($workOrder->skipsAutomatedMessages()) {
            return;
        }

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
        $staffCreated = $workOrder->isStaffCreated();
        $description = $staffCreated ? null : $this->descriptionMessage($workOrder);

        foreach ($owners as $owner) {
            $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

            if ($ownerNumber === null) {
                continue;
            }

            // Each owner gets their own no-login portal link for this request.
            $link = $this->portalLinks->link($workOrder, $owner);

            // Our team entered it: one text that says so, description inline.
            if ($staffCreated) {
                $created = OwnerMessageFormatter::compose(
                    $this->createdMessage($workOrder, $owner, $address),
                    $workOrder->work_order_no,
                    $link,
                );

                $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $created, ['variant' => 'staff_created']);

                continue;
            }

            $confirmation = OwnerMessageFormatter::compose(
                $this->confirmationMessage($workOrder, $address),
                $workOrder->work_order_no,
                $link,
            );

            $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $confirmation);

            if ($description !== null) {
                $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $description);
            }
        }
    }

    /**
     * Persist one message to the owner thread (as the WOC) and queue the text.
     *
     * @param  array<string, mixed>  $extra  extra ledger properties for this message
     */
    private function post(WorkOrder $workOrder, Owner $owner, string $ownerNumber, string $fromNumber, string $message, array $extra = []): void
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

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'owner',
            'owner_service_request_sms',
            $ownerNumber,
            $workOrder,
            $message,
            ['owner_id' => $owner->id, 'conversation_id' => $conversation->id] + $extra,
        );
    }

    /**
     * The confirmation message: Chana's approved wording with the work order
     * number and the property address filled in.
     */
    private function confirmationMessage(WorkOrder $workOrder, string $address): string
    {
        // "your property" already stands in for an unknown address, so only add
        // the "at ..." clause when we have a real one.
        //
        // No mention of the PropertyWare email or portal: that email is sent by
        // PropertyWare, not by us, so we cannot confirm the owner received it,
        // and the no-login link below is now the place to read the request.
        return AutomatedMessageTemplates::text('owner_service_request_confirmation_sms', [
            'property' => $address === 'your property' ? 'your property' : "your property at {$address}",
            'work_order_no' => (string) $workOrder->work_order_no,
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
            AutomatedMessageTemplates::text('owner_service_request_description_sms', [
                'description' => AutomatedMessageTemplates::plainPunctuation($description),
            ]),
            $workOrder->work_order_no,
        );
    }

    /**
     * The single "created by our team" text for a work order staff entered in
     * PropertyWare: the WOC ticket's wording, greeting the owner by name (an
     * LLC's name when that is what PropertyWare holds, as the vendor-assignment
     * text already does), with the description inline. "your property" stands
     * in for an unknown address.
     */
    private function createdMessage(WorkOrder $workOrder, Owner $owner, string $address): string
    {
        $name = trim((string) $owner->name) ?: trim($owner->first_name.' '.$owner->last_name);

        return AutomatedMessageTemplates::text('owner_work_order_created_sms', [
            'greeting' => $name !== '' ? "Hi {$name}," : 'Hi,',
            'work_order_no' => (string) $workOrder->work_order_no,
            'property' => $address,
            'description_line' => AutomatedMessageTemplates::descriptionLine($workOrder->description),
        ]);
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
