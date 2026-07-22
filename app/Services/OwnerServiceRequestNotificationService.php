<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerServiceRequestNotificationService
{
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
        // Vacant units have no tenant who submitted the request, so the owner
        // confirmation ("...the service request submitted by your tenant...")
        // would be misleading. When the work order is vacant — the WOC's manual
        // "Vacant" toggle or a turnover job — skip the owner notification
        // entirely (leaving it un-stamped so it never fires for this request).
        if ($workOrder->isVacant()) {
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

        $address = $this->propertyAddress($workOrder);
        $confirmation = $this->confirmationMessage($workOrder, $address);
        $description = $this->descriptionMessage($workOrder);

        foreach ($owners as $owner) {
            $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

            if ($ownerNumber === null) {
                continue;
            }

            $this->post($workOrder, $ownerNumber, $fromNumber, $confirmation);

            if ($description !== null) {
                $this->post($workOrder, $ownerNumber, $fromNumber, $description);
            }
        }
    }

    /**
     * Persist one message to the owner thread (as the WOC) and queue the text.
     */
    private function post(WorkOrder $workOrder, string $ownerNumber, string $fromNumber, string $message): void
    {
        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber,
            'receiver_number' => $ownerNumber,
            'work_order_id' => $workOrder->id,
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

        return "TexasRenters.com would like to confirm that you've received an email copy of the service request submitted by your tenant. "
            ."Please log in to your owner portal and review service request number {$ref} under property address {$address} for full details. "
            ."We will proceed with the estimate and repairs as outlined in your property management agreement.\n"
            ."(Ref: WO#{$ref})";
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

        return "Description\n{$description} (Ref: WO#{$workOrder->work_order_no})";
    }

    /**
     * The property street address, matching the street-only form the WOC uses
     * (e.g. "3326 Jane Way"). The tenant lives at the property, so their address
     * is the primary source; fall back to the building's street address (filled
     * by sync:building-details) for work orders with no tenant address on file,
     * then to a neutral phrase.
     */
    private function propertyAddress(WorkOrder $workOrder): string
    {
        $tenantAddress = trim((string) ($workOrder->requested_by?->address ?? ''));

        if ($tenantAddress !== '') {
            return $tenantAddress;
        }

        $buildingAddress = trim((string) ($workOrder->building?->address ?? ''));

        if ($buildingAddress !== '') {
            return $buildingAddress;
        }

        return 'your property';
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
