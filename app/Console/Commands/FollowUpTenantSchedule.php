<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\TenantMessageFormatter;
use App\Services\TenantPortalLinkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpTenantSchedule extends Command
{
    protected $signature = 'tenants:followup-schedule';

    protected $description = 'Remind the tenant each day about their upcoming service appointment until the date arrives or they reply.';

    /**
     * A safety cap on how many reminders one tenant can receive for a single
     * work order, so a tenant who simply never replies is not texted
     * indefinitely.
     */
    public const MAX_NOTIFICATIONS = 5;

    /**
     * Runs daily. The appointment confirmation already told the tenant when the
     * vendor is coming; this keeps the date in front of them so nobody misses
     * the visit.
     *
     * A tenant is followed up when their work order has a service schedule that
     * was set at least a day ago, and stops as soon as any of these is true:
     *  - the tenant replied (portal message, portal upload, or inbound text)
     *  - the appointment date has arrived (the reminder is moot by then)
     *  - the schedule was cancelled
     *  - the work order is no longer open
     *  - the cap is hit, or the WOC muted tenant automation for this work order
     */
    public function handle(TenantPortalLinkService $portalLinks): int
    {
        if (! config('services.twilio.tenant_schedule_followup_sms')) {
            $this->info('Tenant schedule follow-up is disabled (TENANT_SCHEDULE_FOLLOWUP_SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $startOfToday = now()->startOfDay();
        $dayAgo = now()->subDay();

        // Candidate work orders: open, with a live schedule set at least a day
        // ago whose appointment has not yet arrived. Scoped in SQL so we never
        // load the whole open board.
        $workOrderIds = DB::table('work_orders')
            ->where('status', 'Open')
            ->whereExists(function ($query) use ($dayAgo) {
                $query->select(DB::raw(1))
                    ->from('service_schedules')
                    ->whereColumn('service_schedules.work_order_id', 'work_orders.id')
                    ->where('service_schedules.created_at', '<=', $dayAgo)
                    ->where('service_schedules.scheduled_date', '>', now())
                    ->where('service_schedules.status', '!=', 'cancelled');
            })
            ->pluck('id');

        $sent = 0;

        foreach ($workOrderIds as $workOrderId) {
            $workOrder = WorkOrder::query()
                ->with(['requested_by', 'woc.wocNumber.twilioPhoneNumber', 'building'])
                ->find($workOrderId);

            if (! $workOrder || $workOrder->status !== 'Open') {
                continue;
            }

            // A WOC can mute this work order's tenant automation from the tenant
            // conversation tab. Checked before the day-claim so a muted day is
            // not consumed and the reminders resume if switched back on.
            if ($workOrder->automationPausedFor('tenant')) {
                continue;
            }

            // A turnover/vacant home has no tenant to remind. Skipped, not
            // excluded, so reminders resume if the flag was a mistake.
            if ($workOrder->isVacant()) {
                continue;
            }

            if ($this->followUp($workOrder, $portalLinks, $startOfToday)) {
                $sent++;
            }
        }

        $this->info("Tenant schedule follow-ups sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Send this tenant their daily reminder, or skip them. Returns whether a
     * reminder was actually sent.
     */
    private function followUp(
        WorkOrder $workOrder,
        TenantPortalLinkService $portalLinks,
        Carbon $startOfToday,
    ): bool {
        // Only work orders that already hold a general portal token are
        // followed up: a token is issued the moment this feature sends the
        // tenant a link (vendor assignment, appointment confirmation, or the
        // intake email). That is what keeps the pre-existing backlog — whose
        // tenants were never sent a link — from being blasted on the first run.
        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->first();

        if (! $token || $token->schedule_followup_excluded_at !== null) {
            return false;
        }

        // The tenant engaged in the portal, or has texted back: stop reminding.
        if ($token->hasResponded() || $this->hasRepliedByText($workOrder)) {
            $token->markResponded();

            return false;
        }

        // Claim today's reminder atomically so an overlapping run or a retry
        // never texts the same tenant twice on the same day, and the cap is
        // honored under concurrency.
        $claimed = DB::table('tenant_upload_tokens')
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
            $this->text($workOrder, $token, $portalLinks);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Tenant schedule follow-up failed to send.', [
                'work_order_id' => $workOrder->id,
                'tenant_upload_token_id' => $token->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether the tenant has sent an inbound message on their thread. Inbound
     * means the sender is the tenant's own number rather than one of ours.
     */
    private function hasRepliedByText(WorkOrder $workOrder): bool
    {
        $tenant = $workOrder->requested_by;

        $digits = Conversation::lastTenDigits(
            $tenant?->mobile_phone ?: $tenant?->home_phone
        );

        if ($digits === null) {
            return false;
        }

        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->where('sender_number', 'LIKE', '%'.$digits)
            ->exists();
    }

    /**
     * Post the reminder into the tenant<->WOC thread and text the tenant, so
     * the message threads with the confirmation and their portal.
     */
    private function text(
        WorkOrder $workOrder,
        TenantUploadToken $token,
        TenantPortalLinkService $portalLinks,
    ): void {
        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);

        // sender_number is the WOC/company number so the portal renders this as
        // a message from the coordinator.
        $fromNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_from')
            ?: config('services.twilio.from');

        $message = TenantMessageFormatter::compose(
            $this->messageFor($workOrder),
            $workOrder->work_order_no ?? $workOrder->id,
            $portalLinks->urlFor($token),
        );

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => false,
        ]);

        // No usable numbers: the message is still logged in the tenant's portal
        // thread, but there is nothing to text.
        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, null, $conversation->id);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_schedule_follow_up_sms',
            $tenantNumber,
            $workOrder,
            $message,
            ['tenant_upload_token_id' => $token->id, 'conversation_id' => $conversation->id],
        );
    }

    /**
     * The reminder wording. Placeholder pending the approved canned message
     * from operations (Chana); swap this text only, no structural change.
     */
    private function messageFor(WorkOrder $workOrder): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';

        $appointment = $workOrder->service_schedules()
            ->withoutGlobalScopes()
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('scheduled_date')
            ->first();

        $when = $appointment && filled($appointment->scheduled_date)
            ? Carbon::parse($appointment->scheduled_date)
            : null;

        $line = $when === null
            ? 'This is a reminder about the upcoming service appointment for your home.'
            : ($when->format('H:i') === '00:00'
                ? 'This is a reminder that your service appointment is set for '.$when->format('l, F j, Y').'.'
                : 'This is a reminder that your service appointment is set for '.$when->format('l, F j, Y').' at '.$when->format('g:i A').'.');

        return TenantMessageFormatter::paragraphs([
            $greeting,
            'This is TexasRenters.com Maintenance. '.$line,
            'Please make sure someone 18 or older is home to let the technician in. If that time no longer works, just reply here and we will help reschedule.',
            'Thank you!',
        ]);
    }

    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits !== '' ? '+'.$digits : null;
    }
}
