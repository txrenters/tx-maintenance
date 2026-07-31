<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\WorkOrder;
use App\Services\OwnerPortalLinkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpOwnerSchedule extends Command
{
    protected $signature = 'owners:followup-schedule';

    protected $description = 'Text each owner every day after a service appointment is scheduled, asking whether they want to join the technician call or approve the work order, until they reply.';

    /**
     * A safety cap on how many follow-ups one owner can receive for a single
     * work order, so an owner who simply never replies is not texted
     * indefinitely.
     */
    public const MAX_NOTIFICATIONS = 5;

    /**
     * Runs daily. The appointment notification already asked the owner whether
     * they want to join the technician call or approve the work order; nothing
     * chased that question before this command.
     *
     * An owner is followed up when their work order has a service schedule that
     * was set at least a day ago, and stops as soon as any of these is true:
     *  - the owner replied (portal message or inbound text) -> responded_at
     *  - the appointment date has arrived (the question is moot by then)
     *  - the work order is no longer open
     *  - the cap is hit, or the WOC muted owner automation for this work order
     */
    public function handle(OwnerPortalLinkService $portalLinks): int
    {
        // Gated off by default so local/testing never texts a real owner.
        if (! config('services.twilio.owner_schedule_followup_sms')) {
            $this->info('Owner schedule follow-up is disabled (OWNER_SCHEDULE_FOLLOWUP_SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $startOfToday = now()->startOfDay();
        $dayAgo = now()->subDay();

        // Candidate work orders: open, with a schedule set at least a day ago
        // whose appointment has not yet arrived. Scoped in SQL so we never load
        // the whole open board.
        $workOrderIds = DB::table('work_orders')
            ->where('status', 'Open')
            ->whereExists(function ($query) use ($dayAgo) {
                $query->select(DB::raw(1))
                    ->from('service_schedules')
                    ->whereColumn('service_schedules.work_order_id', 'work_orders.id')
                    ->where('service_schedules.created_at', '<=', $dayAgo)
                    ->where('service_schedules.scheduled_date', '>', now());
            })
            ->pluck('id');

        $sent = 0;

        foreach ($workOrderIds as $workOrderId) {
            $workOrder = WorkOrder::query()
                ->with(['owners', 'woc.wocNumber.twilioPhoneNumber', 'building'])
                ->find($workOrderId);

            if (! $workOrder || $workOrder->status !== 'Open') {
                continue;
            }

            // A WOC can mute this work order's owner automation from the owner
            // conversation tab; skip while paused (it resumes if switched back
            // on and the appointment is still ahead).
            if ($workOrder->automationPausedFor('owner')) {
                continue;
            }

            foreach ($workOrder->notifiableOwners() as $owner) {
                if ($this->followUpOwner($workOrder, $owner, $portalLinks, $startOfToday)) {
                    $sent++;
                }
            }
        }

        $this->info("Owner schedule follow-ups sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Send one owner their daily follow-up, or skip them. Returns whether a
     * follow-up was actually sent.
     */
    private function followUpOwner(
        WorkOrder $workOrder,
        Owner $owner,
        OwnerPortalLinkService $portalLinks,
        Carbon $startOfToday,
    ): bool {
        // Only owners who already hold a portal token are followed up: a token
        // is issued the moment this feature sends them a link (intake, vendor
        // assignment, or the appointment notification). That is what keeps the
        // pre-existing backlog — whose owners were never asked the question and
        // never got a link — from being blasted on the first run, without
        // needing a backfill.
        $token = OwnerPortalToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('owner_id', $owner->id)
            ->first();

        if (! $token || $token->schedule_followup_excluded_at !== null) {
            return false;
        }

        // The owner replied in the portal, or has texted back since the token
        // was issued: they have engaged, so stop asking.
        if ($token->hasResponded() || $this->hasRepliedByText($workOrder, $owner)) {
            $token->markResponded();

            return false;
        }

        // Claim today's follow-up atomically so an overlapping run or a retry
        // never texts the same owner twice on the same day, and the cap is
        // honored under concurrency.
        $claimed = DB::table('owner_portal_tokens')
            ->where('id', $token->id)
            ->whereNull('responded_at')
            ->whereNull('schedule_followup_excluded_at')
            ->where('schedule_followup_count', '<', self::MAX_NOTIFICATIONS)
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('schedule_followup_last_sent_at')
                    ->orWhere('schedule_followup_last_sent_at', '<', $startOfToday);
            })
            ->update([
                'schedule_followup_last_sent_at' => now(),
                'schedule_followup_count' => DB::raw('schedule_followup_count + 1'),
            ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $this->text($workOrder, $owner, $token, $portalLinks);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Owner schedule follow-up failed to send.', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether this owner has sent an inbound message on the owner thread since
     * their portal token was issued (i.e. since the appointment notification).
     */
    private function hasRepliedByText(WorkOrder $workOrder, Owner $owner): bool
    {
        $digits = Conversation::lastTenDigits(
            filled($owner->mobile) ? $owner->mobile : $owner->phone
        );

        if ($digits === null) {
            return false;
        }

        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->where('sender_number', 'LIKE', '%'.$digits)
            ->exists();
    }

    /**
     * Post the follow-up into the owner<->WOC thread and text the owner, so the
     * message threads with the appointment notification and their portal.
     */
    private function text(
        WorkOrder $workOrder,
        Owner $owner,
        OwnerPortalToken $token,
        OwnerPortalLinkService $portalLinks,
    ): void {
        $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

        // sender_number is the WOC/company number so the portal renders this as
        // a message from the coordinator.
        $fromNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $message = $this->messageFor($workOrder).$portalLinks->smsLine($workOrder, $owner);

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $ownerNumber,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        // No usable numbers: the message is still logged in the owner's portal
        // thread, but there is nothing to text.
        if (blank($ownerNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($ownerNumber, $fromNumber, $message, null, $conversation->id);
    }

    /**
     * The follow-up wording. Placeholder pending the approved canned message
     * from operations (Chana); swap this text only, no structural change.
     */
    private function messageFor(WorkOrder $workOrder): string
    {
        $ref = $workOrder->work_order_no ?? $workOrder->id;
        $address = $workOrder->propertyAddress();
        $property = $address !== null ? ' at '.$address : '';

        return 'Hello, this is TexasRenters.com Maintenance following up on the scheduled service appointment'
            .$property.' (WO#'.$ref.'). '
            .'Would you like to be available at the appointment time to speak with the technician, or to approve the work order? '
            .'Please reply and let us know so we can coordinate accordingly.';
    }
}
