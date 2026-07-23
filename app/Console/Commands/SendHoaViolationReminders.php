<?php

namespace App\Console\Commands;

use App\Models\TenantUploadToken;
use App\Services\HoaViolationConfirmationSender;
use App\Services\TenantPortalLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drives the HOA violation workflow after intake, once per day:
 *   1. Reminders — text the tenant their portal link every business day until
 *      they upload photos or the 5-business-day deadline passes.
 *   2. Escalation — when the deadline passes without completion, flag staff to
 *      assign a vendor (once).
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
     * Daily reminders until completion or deadline. Weekends are skipped and a
     * once-per-day claim (last_notified_at) survives overlapping runs/retries.
     */
    private function sendReminders(TenantPortalLinkService $linkService): int
    {
        if (now()->isWeekend()) {
            return 0;
        }

        $startOfToday = now()->startOfDay();

        $tokens = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereNull('completed_at')
            ->whereNotNull('hoa_deadline_at')
            ->where('hoa_deadline_at', '>=', now())
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('last_notified_at')
                    ->orWhere('last_notified_at', '<', $startOfToday);
            })
            ->get();

        $sent = 0;

        foreach ($tokens as $token) {
            // Claim today's reminder atomically before sending.
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

            $linkService->remind($token->refresh());
            $sent++;
        }

        return $sent;
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
            $claimed = TenantUploadToken::query()
                ->whereKey($token->id)
                ->whereNull('escalation_flagged_at')
                ->update(['escalation_flagged_at' => now()]);

            if ($claimed === 0 || ! $token->work_order) {
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
