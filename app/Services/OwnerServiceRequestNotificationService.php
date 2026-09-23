<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerServiceRequestNotificationService
{
    public function __construct(
        private OwnerPortalLinkService $portalLinks,
        private TenantEasyFixService $easyFix,
        private OwnerWorkOrderEmailSender $emails,
    ) {}

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
     * An owner with no phone on file gets the same notice as one email
     * instead (confirmation and description together, same portal link), so
     * the half of owners PropertyWare holds without a phone are not left
     * silent.
     *
     * Gated off by default, fired at most once per work order, and wrapped so a
     * failure is logged but never breaks intake.
     *
     * @param  bool  $force  a WOC pressed "send anyway" on the owner tab, so
     *                       send even though this work order reads as muted
     *                       (most often a website request whose lease has not
     *                       been attached in PropertyWare yet)
     */
    public function notify(WorkOrder $workOrder, bool $force = false): void
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
            $this->send($workOrder, $force);
        } catch (\Throwable $exception) {
            Log::error('Owner service-request notification failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WorkOrder $workOrder, bool $force = false): void
    {
        // A turnover/re-key/vacant or refresh-cleaning work order is not a
        // tenant service request — it is work the company already set in
        // motion (re-keys go to Express Key, cleanings are company-ordered),
        // so the "we have received a new service request" confirmation reads
        // as noise or as a request the owner never made. Skip it, leaving the
        // one-shot stamp clear so a re-categorized work order can still
        // notify. (This skip existed before, was removed by the 07-29 intake
        // reword, and is deliberately restored per the 08-06 ticket.)
        if (! $force && $workOrder->skipsAutomatedMessages()) {
            return;
        }

        // HOA violations are not service requests. The generic confirmation plus
        // a raw dump of the notice's items and remedies reads to the owner as a
        // repair request, so skip the intake notification entirely and leave the
        // HOA flow to message them. Never forceable: the HOA flow messages them.
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
        // numbers de-duplicated. Owners with no phone but an email are
        // emailed instead; owners with neither get nothing.
        $textOwners = $workOrder->notifiableOwners();
        $emailOwners = $this->emailFallbackOwners($workOrder);
        $fromNumber = $this->fromNumber($workOrder);

        // Nothing to send from: leave the stamp clear so the texts still go
        // out once a number is configured (mirrors the live vendor-assignment
        // owner notification, which also skips silently).
        if ($textOwners->isNotEmpty() && blank($fromNumber)) {
            return;
        }

        if ($textOwners->isEmpty() && $emailOwners->isEmpty()) {
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

        // When the tenant is being sent the easy-fix how-to, the owner is
        // told that instead of "we will arrange the estimate". Only when the
        // tenant really is being told - the same gate, mute and reachability
        // the tenant text itself checks - so the owner is never promised a
        // text the tenant never got. The description text still follows.
        $verdict = $this->easyFix->assess($workOrder);
        $easyFix = ! $staffCreated && $this->easyFix->tenantWillBeTold($workOrder, $verdict);

        foreach ($textOwners as $owner) {
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
                $easyFix
                    ? $this->easyFixConfirmation($workOrder, $address, $verdict['item'])
                    : $this->confirmationMessage($workOrder, $address),
                $workOrder->work_order_no,
                $link,
            );

            $this->post(
                $workOrder,
                $owner,
                $ownerNumber,
                $fromNumber,
                $confirmation,
                $easyFix ? ['easy_fix_key' => $verdict['key']] : [],
                $easyFix ? 'owner_easy_fix_sms' : 'owner_service_request_sms',
            );

            if ($description !== null) {
                $this->post($workOrder, $owner, $ownerNumber, $fromNumber, $description);
            }
        }

        foreach ($emailOwners as $owner) {
            $this->email($workOrder, $owner, $address, $staffCreated, $easyFix, $verdict);
        }
    }

    /**
     * The owners this notice reaches by email: those with no usable phone but
     * a real email address, one per address (a couple sharing an inbox gets a
     * single email). Empty when the email fallback is switched off.
     *
     * @return Collection<int, Owner>
     */
    private function emailFallbackOwners(WorkOrder $workOrder): Collection
    {
        if (! config('services.work_order.owner_intake_email')) {
            return collect();
        }

        return $workOrder->owners
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership)
            ->filter(fn (Owner $owner): bool => $workOrder->normalizedOwnerPhone($owner) === null)
            ->filter(fn (Owner $owner): bool => $this->ownerEmail($owner) !== null)
            ->unique(fn (Owner $owner): string => (string) $this->ownerEmail($owner))
            ->values();
    }

    /**
     * The owner's email address, lower-cased, or null when blank or not an
     * address at all (PropertyWare sends "" rather than null, and the odd
     * placeholder).
     */
    private function ownerEmail(Owner $owner): ?string
    {
        $email = strtolower(trim((string) $owner->email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Persist one message to the owner thread (as the WOC) and queue the text.
     *
     * @param  array<string, mixed>  $extra  extra ledger properties for this message
     * @param  string  $automation  the ledger key this message is recorded under
     */
    private function post(WorkOrder $workOrder, Owner $owner, string $ownerNumber, string $fromNumber, string $message, array $extra = [], string $automation = 'owner_service_request_sms'): void
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
            $automation,
            $ownerNumber,
            $workOrder,
            $message,
            ['owner_id' => $owner->id, 'conversation_id' => $conversation->id] + $extra,
        );
    }

    /**
     * The email twin of the texts for an owner with no phone on file: the
     * same editable wording (the created-by-our-team text, or the
     * confirmation with the request description under it) with their portal
     * link as a button, from the work-orders mailbox, recorded on the owner's
     * email history like the vendor-assignment email.
     *
     * @param  array<string, mixed>|null  $verdict  the easy-fix verdict when $easyFix is true
     */
    private function email(WorkOrder $workOrder, Owner $owner, string $address, bool $staffCreated, bool $easyFix, ?array $verdict): void
    {
        $to = (string) $this->ownerEmail($owner);
        $reference = $workOrder->work_order_no ?? $workOrder->id;

        $body = match (true) {
            $staffCreated => $this->createdMessage($workOrder, $owner, $address),
            $easyFix => $this->easyFixConfirmation($workOrder, $address, $verdict['item']),
            default => $this->confirmationMessage($workOrder, $address),
        };

        $subject = $workOrder->subjectWithProperty(
            ($staffCreated ? 'A work order has been created - Work Order #' : 'New service request - Work Order #').$reference,
        );

        $html = view('emails.owner-service-request', [
            'workOrder' => $workOrder,
            'owner' => $owner,
            'body' => $body,
            'staffCreated' => $staffCreated,
            // The created-by-our-team wording already carries the description.
            'description' => $staffCreated ? null : trim((string) $workOrder->description),
            'portalLink' => $this->portalLinks->link($workOrder, $owner),
        ])->render();

        $extra = [];

        if ($staffCreated) {
            $extra['variant'] = 'staff_created';
        } elseif ($easyFix) {
            $extra['variant'] = 'easy_fix';
            $extra['easy_fix_key'] = $verdict['key'];
        }

        $notification = $this->emails->send(
            owner: $owner,
            workOrder: $workOrder,
            to: $to,
            mailbox: (string) config('services.microsoft.mailbox'),
            subject: $subject,
            html: $html,
            trustedHtml: true,
            metadata: ['automation' => 'owner_service_request_email'] + $extra,
            type: 'service_request',
        );

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_EMAIL,
            'owner',
            'owner_service_request_email',
            $to,
            $workOrder,
            $body,
            ['owner_id' => $owner->id, 'owner_email_notification_id' => $notification->id, 'subject' => $subject] + $extra,
        );
    }

    /**
     * The confirmation when the tenant has been sent the easy-fix how-to:
     * no estimate is being arranged yet, the tenant is trying the handbook
     * fix first.
     *
     * @param  array<string, mixed>  $item
     */
    private function easyFixConfirmation(WorkOrder $workOrder, string $address, array $item): string
    {
        return AutomatedMessageTemplates::text('owner_easy_fix_sms', [
            'property' => $address === 'your property' ? 'your property' : "your property at {$address}",
            'work_order_no' => (string) $workOrder->work_order_no,
            'item_label' => (string) $item['label'],
        ]);
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
