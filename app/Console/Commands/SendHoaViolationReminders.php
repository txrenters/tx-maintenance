<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\TenantUploadToken;
use App\Services\HoaViolationConfirmationSender;
use App\Services\TenantPortalLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Drives the HOA violation workflow after intake, once per day:
 *   1. Reminders — text the tenant their portal link every business day until
 *      they upload photos (via the portal, or texted into the conversation) or
 *      the five-message window is spent. Day 4 warns them a vendor will be
 *      sent if it is not taken care of.
 *   2. Escalation — when the 5-business-day deadline passes without completion,
 *      flag staff to assign that vendor (once).
 *   3. Confirmation — when the tenant completes (photos uploaded), email the
 *      tenant + owner a link to the before/after photos (once).
 *
 * The human still sets everything in motion (uploads the notice, assigns the
 * vendor); this only automates the sends. Every step is idempotent and gated,
 * so running it in local/testing texts and emails no one.
 */
class SendHoaViolationReminders extends Command
{
    protected $signature = 'hoa:send-reminders';

    protected $description = 'Send daily HOA violation reminders, flag overdue violations for vendor assignment, and email corrected confirmations.';

    public function handle(
        TenantPortalLinkService $linkService,
        HoaViolationConfirmationSender $confirmationSender,
    ): int {
        $reminders = $this->sendReminders($linkService);
        $escalations = $this->flagOverdue();
        $confirmations = $this->sendConfirmations($confirmationSender);

        $this->info(sprintf(
            'HOA violations: %d reminders, %d escalations flagged, %d confirmations sent.',
            $reminders,
            $escalations,
            $confirmations,
        ));

        return self::SUCCESS;
    }

    /**
     * Daily reminders until the tenant completes or the five-message window is
     * spent (the intake text plus four daily reminders, the fourth of which
     * warns that a vendor is coming). Weekends are skipped and a once-per-day
     * claim (last_notified_at) survives overlapping runs/retries.
     */
    private function sendReminders(TenantPortalLinkService $linkService): int
    {
        if (now()->isWeekend()) {
            return 0;
        }

        $startOfToday = now()->startOfDay();

        $tokens = TenantUploadToken::query()
            ->with('work_order')
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereNull('completed_at')
            ->where('notified_count', '<', TenantPortalLinkService::HOA_MAX_NOTIFICATIONS)
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('last_notified_at')
                    ->orWhere('last_notified_at', '<', $startOfToday);
            })
            ->get();

        $sent = 0;

        foreach ($tokens as $token) {
            // Stop reminders once the WOC closes the work order — she closes it
            // after the tenant sends proof, so "closed" is the manual counterpart
            // to the tenant's photo upload (completed_at). Either one ends them.
            if (! $token->work_order || $token->work_order->status !== 'Open') {
                continue;
            }

            // Stop reminders once a vendor is on the job: the tenant missed the
            // self-fix window and staff scheduled a vendor to correct it, so
            // there is no point nagging the tenant any further.
            if ($token->work_order->vendors()->exists()) {
                continue;
            }

            // Tenants often reply with proof photos as picture messages instead
            // of using the portal link. That never stamps completed_at, so
            // treat an inbound MMS on the tenant thread as proof: stop nagging
            // and flag staff (once) to review the photos and close it out.
            if ($this->tenantTextedPhotos($token)) {
                $this->flagPhotosForReview($token);

                continue;
            }

            // Claim today's reminder atomically before sending.
            $previousClaim = $token->last_notified_at;

            $claimed = TenantUploadToken::query()
                ->whereKey($token->id)
                ->where(function ($query) use ($startOfToday) {
                    $query->whereNull('last_notified_at')
                        ->orWhere('last_notified_at', '<', $startOfToday);
                })
                ->update(['last_notified_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            // Nothing went out — most often no tenant, or no phone, on the work
            // order. Put the claim back, or this token is marked "done for
            // today" for a text that never happened: the tenant hears nothing
            // all the way to the deadline while every run reports a healthy
            // send. A successful send sets its own last_notified_at.
            if (! $linkService->remind($token->refresh())) {
                TenantUploadToken::query()
                    ->whereKey($token->id)
                    ->update(['last_notified_at' => $previousClaim]);

                continue;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * The tenant replied with a picture (or video) message on this work order's
     * tenant conversation after the violation notice went out. Inbound rows are
     * the ones with is_read = false — every outbound/automated writer sets it
     * true on insert.
     */
    private function tenantTextedPhotos(TenantUploadToken $token): bool
    {
        return Conversation::query()
            ->where('work_order_id', $token->work_order_id)
            ->where('conversation_type', 'tenant')
            ->where('is_read', false)
            ->where('is_mms', true)
            ->where('created_at', '>=', $token->created_at)
            ->exists();
    }

    /**
     * Staff (activity-log notification, once per work order): the tenant sent
     * photos by text, so a human needs to review them and close the work order
     * — that close is what ends the escalation path too.
     */
    private function flagPhotosForReview(TenantUploadToken $token): void
    {
        $workOrder = $token->work_order;

        $alreadyFlagged = Activity::query()
            ->where('event', 'hoa_violation_photos_by_text')
            ->forSubject($workOrder)
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        activity()
            ->performedOn($workOrder)
            ->event('hoa_violation_photos_by_text')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'message' => 'Tenant texted photos for this HOA violation — review them and close the work order if it is resolved.',
                'read' => false,
            ])
            ->log('HOA VIOLATION PHOTOS RECEIVED - Work Order #'.$workOrder->work_order_no);
    }

    /**
     * Deadline passed without completion: flag staff (activity-log notification)
     * to assign a vendor. Stamped once via escalation_flagged_at.
     */
    private function flagOverdue(): int
    {
        $tokens = TenantUploadToken::query()
            ->with('work_order')
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereNull('completed_at')
            ->whereNull('escalation_flagged_at')
            ->whereNotNull('hoa_deadline_at')
            ->where('hoa_deadline_at', '<', now())
            ->get();

        $flagged = 0;

        foreach ($tokens as $token) {
            // A closed work order needs no vendor escalation — the WOC has
            // already handled it (she closes it once the tenant complies).
            if (! $token->work_order || $token->work_order->status !== 'Open') {
                continue;
            }

            // Already has a vendor scheduled — the escalation's whole purpose
            // (get a vendor assigned) is met, so don't flag it as needing one.
            if ($token->work_order->vendors()->exists()) {
                continue;
            }

            // The tenant was never told. "Assign a vendor, the tenant did not
            // fix it in time" is only true if we asked them to, and the whole
            // point of the five-message window is the chance to fix it for
            // free. Sending a vendor to a tenant who never heard from us bills
            // the owner for work the tenant was never given a chance to do, so
            // flag the silence for a human instead and leave the escalation
            // stamp clear — it escalates normally once they have been texted.
            if ($token->notified_count < 1) {
                $this->flagNeverNotified($token);

                continue;
            }

            $claimed = TenantUploadToken::query()
                ->whereKey($token->id)
                ->whereNull('escalation_flagged_at')
                ->update(['escalation_flagged_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $this->flagStaff($token);
                $flagged++;
            } catch (\Throwable $exception) {
                Log::error('HOA violation escalation flag failed.', [
                    'tenant_upload_token_id' => $token->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $flagged;
    }

    /**
     * The deadline passed but the tenant was never texted, so this needs a
     * human before it can become a vendor's job: usually no tenant linked on
     * the work order, or no phone on the one that is (see hoa:relink-tenants).
     * One bell per work order, ever — the nightly run would otherwise re-raise
     * the same alert until someone fixes it.
     */
    private function flagNeverNotified(TenantUploadToken $token): void
    {
        $workOrder = $token->work_order;

        $alreadyFlagged = Activity::query()
            ->where('event', 'hoa_violation_never_notified')
            ->forSubject($workOrder)
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        activity()
            ->performedOn($workOrder)
            ->event('hoa_violation_never_notified')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'message' => 'HOA violation deadline passed but the tenant was never texted — check that a tenant with a phone number is linked, then decide whether to give them time or assign a vendor.',
                'deadline' => $token->hoa_deadline_at?->toDateString(),
                'read' => false,
            ])
            ->log('HOA VIOLATION - TENANT NEVER NOTIFIED - Work Order #'.$workOrder->work_order_no);
    }

    private function flagStaff(TenantUploadToken $token): void
    {
        $workOrder = $token->work_order;

        activity()
            ->performedOn($workOrder)
            ->event('hoa_violation_overdue')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'message' => 'HOA violation deadline passed without tenant completion — assign a vendor to correct it.',
                'deadline' => $token->hoa_deadline_at?->toDateString(),
                'read' => false,
            ])
            ->log('HOA VIOLATION OVERDUE - Work Order #'.$workOrder->work_order_no);
    }

    /**
     * Completed HOA violations (photos uploaded) get a one-time corrected
     * confirmation email to tenant + owner. Stamped via confirmation_sent_at.
     */
    private function sendConfirmations(HoaViolationConfirmationSender $sender): int
    {
        if (! config('services.twilio.hoa_violation_sms')) {
            return 0;
        }

        $tokens = TenantUploadToken::query()
            ->with('work_order')
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereNotNull('completed_at')
            ->whereNull('confirmation_sent_at')
            ->get();

        $sent = 0;

        foreach ($tokens as $token) {
            if (! $token->work_order) {
                continue;
            }

            // A WOC can mute this work order's tenant automation from the tenant
            // conversation tab; skip (unstamped) so it resumes if switched back on.
            if ($token->work_order->automationPausedFor('tenant')) {
                continue;
            }

            // Only confirm once at least one proof photo exists.
            $hasPhotos = DB::table('attachments')
                ->where('work_order_id', $token->work_order_id)
                ->where('uploaded_via_tenant_portal', true)
                ->exists();

            if (! $hasPhotos) {
                continue;
            }

            $claimed = TenantUploadToken::query()
                ->whereKey($token->id)
                ->whereNull('confirmation_sent_at')
                ->update(['confirmation_sent_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $sender->send($token->refresh());
                $sent++;
            } catch (\Throwable $exception) {
                // Roll back the stamp so the next run retries.
                TenantUploadToken::query()->whereKey($token->id)->update(['confirmation_sent_at' => null]);

                Log::error('HOA violation confirmation email failed.', [
                    'tenant_upload_token_id' => $token->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
