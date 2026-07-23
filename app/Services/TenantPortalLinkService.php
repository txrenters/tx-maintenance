<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

class TenantPortalLinkService
{
    /**
     * Text the tenant a no-login portal link so they can upload photos of the
     * issue (tenant easy fix). Creates the token on first send; later calls
     * send reminders until the tenant completes or the reminder cap is hit.
     *
     * Gated off by default and log-never-throw, so it can run from a scheduled
     * command without ever texting anyone until enabled in production.
     */
    public const MAX_NOTIFICATIONS = 3; // 1 initial + 2 reminders

    public function sendLink(WorkOrder $workOrder): void
    {
        if (! config('services.twilio.tenant_portal_sms')) {
            return;
        }

        try {
            $this->send($workOrder);
        } catch (\Throwable $exception) {
            Log::error('Tenant portal link failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function remind(TenantUploadToken $token): void
    {
        if (! $this->enabledFor($token->purpose)) {
            return;
        }

        try {
            $workOrder = $token->work_order;

            if (! $workOrder || $token->isCompleted()) {
                return;
            }

            if ($token->purpose === TenantUploadToken::PURPOSE_HOA_VIOLATION) {
                // HOA reminders are daily and bounded by the notice deadline,
                // not the easy-fix notification cap.
                if ($token->hoa_deadline_at !== null && $token->hoa_deadline_at->isPast()) {
                    return;
                }

                $this->text($workOrder, $token, $this->hoaReminderMessage($workOrder, $token));

                return;
            }

            if ($token->notified_count >= self::MAX_NOTIFICATIONS) {
                return;
            }

            $this->text($workOrder, $token, $this->reminderMessage($workOrder, $token));
        } catch (\Throwable $exception) {
            Log::error('Tenant portal reminder failed to send.', [
                'tenant_upload_token_id' => $token->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Text the tenant their HOA-violation portal link for a token the intake
     * flow has already created (the token carries the notice deadline).
     */
    public function sendHoaLink(TenantUploadToken $token): void
    {
        if (! config('services.twilio.hoa_violation_sms')) {
            return;
        }

        try {
            $workOrder = $token->work_order;

            if (! $workOrder || $token->isCompleted() || $token->notified_count > 0) {
                return;
            }

            $this->text($workOrder, $token, $this->hoaInitialMessage($workOrder, $token));
        } catch (\Throwable $exception) {
            Log::error('HOA violation portal link failed to send.', [
                'tenant_upload_token_id' => $token->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function enabledFor(?string $purpose): bool
    {
        return $purpose === TenantUploadToken::PURPOSE_HOA_VIOLATION
            ? (bool) config('services.twilio.hoa_violation_sms')
            : (bool) config('services.twilio.tenant_portal_sms');
    }

    private function send(WorkOrder $workOrder): void
    {
        // One token per work order per purpose: if one exists, this work order
        // has already been sent its link (reminders are handled separately).
        $existing = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->exists();

        if ($existing) {
            return;
        }

        $token = TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
        ]);

        $this->text($workOrder, $token, $this->initialMessage($workOrder, $token));
    }

    /**
     * Persist the message to the tenant conversation thread (as the WOC) and
     * queue the text, then record the notification on the token.
     */
    private function text(WorkOrder $workOrder, TenantUploadToken $token, string $message): void
    {
        $workOrder->loadMissing(['requested_by', 'woc.wocNumber.twilioPhoneNumber']);

        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);
        $fromNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => false,
        ]);

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, null, $conversation->id);

        $token->update([
            'last_notified_at' => now(),
            'notified_count' => $token->notified_count + 1,
        ]);
    }

    private function initialMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no;

        return $greeting."this is TexasRenters.com Maintenance about your service request (WO#{$ref}). "
            .'To help us resolve it quickly, please upload photos of the issue using this secure link — no login needed: '
            .route('tenant.portal.show', $token->token)
            ."\n(Ref: WO#{$ref})";
    }

    private function reminderMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no;

        return $greeting."a quick reminder from TexasRenters.com Maintenance: please upload photos for your service request (WO#{$ref}) using this secure link — no login needed: "
            .route('tenant.portal.show', $token->token)
            ."\n(Ref: WO#{$ref})";
    }

    private function hoaInitialMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no ?? $workOrder->id;
        $deadline = $token->hoa_deadline_at?->timezone('America/Chicago')->format('l, M j');

        return $greeting.'this is TexasRenters.com Maintenance. The HOA has issued a violation notice for your property'
            .' (WO#'.$ref.'). Please correct the items and upload proof photos'
            .($deadline ? " by {$deadline}" : ' within 5 business days')
            .' using this secure link — no login needed: '
            .route('tenant.portal.show', $token->token)
            ."\n(Ref: WO#{$ref})";
    }

    private function hoaReminderMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no ?? $workOrder->id;
        $deadline = $token->hoa_deadline_at?->timezone('America/Chicago')->format('l, M j');

        return $greeting.'a reminder from TexasRenters.com Maintenance: the HOA violation items for your property'
            .' (WO#'.$ref.') must be completed'
            .($deadline ? " by {$deadline}" : ' within 5 business days of the notice')
            .'. Please upload proof photos here — no login needed: '
            .route('tenant.portal.show', $token->token)
            ."\n(Ref: WO#{$ref})";
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
