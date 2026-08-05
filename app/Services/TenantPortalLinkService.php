<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * HOA violations run a fixed five-business-day window: the notice text on
     * day 1, then one reminder a day through day 5. The wording is chosen by
     * which of those days it is, so the day-4 message can warn the tenant that
     * a vendor is coming next.
     */
    public const HOA_MAX_NOTIFICATIONS = 5; // 1 initial + 4 daily reminders

    /**
     * This work order's tenant token for the given purpose, created on first
     * use. The general PURPOSE_WORK_ORDER token is the one every automated
     * tenant message links to; the easy-fix and HOA purposes keep their own so
     * their completion and reminder state stay independent.
     */
    public function tokenFor(WorkOrder $workOrder, string $purpose = TenantUploadToken::PURPOSE_WORK_ORDER): TenantUploadToken
    {
        return TenantUploadToken::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id, 'purpose' => $purpose],
            ['token' => TenantUploadToken::generateUniqueToken()],
        );
    }

    /**
     * The tenant's portal URL, or null when a token could not be issued.
     *
     * Every caller is an automated message that must not break if token
     * creation fails, so this is log-never-throw; callers simply omit the link
     * line rather than losing the whole notification.
     */
    public function link(WorkOrder $workOrder, string $purpose = TenantUploadToken::PURPOSE_WORK_ORDER): ?string
    {
        try {
            return $this->urlFor($this->tokenFor($workOrder, $purpose));
        } catch (\Throwable $exception) {
            Log::error('Tenant portal link could not be issued.', [
                'work_order_id' => $workOrder->id,
                'purpose' => $purpose,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The portal URL for a token we already hold. The single place that knows
     * the URL shape.
     */
    public function urlFor(TenantUploadToken $token): string
    {
        return route('tenant.portal.show', $token->token);
    }

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
                // HOA reminders are daily and bounded by their own five-message
                // window, not the easy-fix notification cap.
                if ($token->notified_count >= self::HOA_MAX_NOTIFICATIONS) {
                    return;
                }

                $this->text($workOrder, $token, $this->hoaReminderMessage($workOrder, $token), 'tenant_hoa_violation_reminder_sms');

                return;
            }

            if ($token->notified_count >= self::MAX_NOTIFICATIONS) {
                return;
            }

            $this->text($workOrder, $token, $this->reminderMessage($workOrder, $token), 'tenant_portal_link_reminder_sms');
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

            $this->text($workOrder, $token, $this->hoaInitialMessage($workOrder, $token), 'tenant_hoa_violation_link_sms');
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

        $this->text($workOrder, $token, $this->initialMessage($workOrder, $token), 'tenant_portal_link_sms');
    }

    /**
     * Persist the message to the tenant conversation thread (as the WOC) and
     * queue the text, then record the notification on the token. The automation
     * key names which portal-link message this is on the IT Tools automated
     * messages log.
     */
    private function text(WorkOrder $workOrder, TenantUploadToken $token, string $message, string $automationKey): void
    {
        // A WOC can mute this work order's tenant automation from the tenant
        // conversation tab; manual sends are unaffected.
        if ($workOrder->automationPausedFor('tenant')) {
            return;
        }

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

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            $automationKey,
            $tenantNumber,
            $workOrder,
            $message,
            ['tenant_upload_token_id' => $token->id, 'conversation_id' => $conversation->id],
        );

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
            .$this->urlFor($token)
            ."\n(Ref: WO#{$ref})";
    }

    private function reminderMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no;

        return $greeting."a quick reminder from TexasRenters.com Maintenance: please upload photos for your service request (WO#{$ref}) using this secure link — no login needed: "
            .$this->urlFor($token)
            ."\n(Ref: WO#{$ref})";
    }

    private function hoaInitialMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $ref = $workOrder->work_order_no ?? $workOrder->id;
        $summary = $this->hoaViolationSummary($workOrder);

        return $greeting.'this is TexasRenters.com Maintenance. We received an HOA notice for your home'
            .' (WO#'.$ref.')'
            .($summary
                ? ' regarding the following: '.$summary
                : ' listing a few items that need a little attention')
            .'. Whenever you have a chance, we\'d truly appreciate it if you could take care of'
            .($summary ? ' it' : ' them').' and send us a quick photo as proof.'
            .' Here is your secure link — no login needed: '
            .$this->urlFor($token)
            .". Thank you so much for your help!\n(Ref: WO#{$ref})";
    }

    private function hoaReminderMessage(WorkOrder $workOrder, TenantUploadToken $token): string
    {
        $ref = $workOrder->work_order_no ?? $workOrder->id;

        $variants = self::hoaReminderVariants(
            (string) ($workOrder->requested_by?->first_name ?? ''),
            $ref,
            $this->hoaViolationSummary($workOrder),
            $this->urlFor($token),
        );

        // Pick by which day of the window this is, not by rotation: the intake
        // text is day 1 (notified_count 1), so this reminder is day
        // notified_count + 1 and the day-4 vendor warning always lands on the
        // fourth message. Index 0 only comes up if the intake text never went
        // out; clamping keeps a late run on the final day's wording.
        $index = min(max(0, (int) $token->notified_count), count($variants) - 1);

        return $variants[$index]."\n(Ref: WO#{$ref})";
    }

    /**
     * One phrasing per day of the HOA window, indexed by day: the follow-ups
     * read like a person checking in rather than a bot repeating one templated
     * line, and day 4 gives the tenant a polite heads-up that a vendor comes
     * next. Index 0 is the day-agnostic wording, used only when the intake text
     * never went out. Public + static so the demo seeder shows the same texts.
     *
     * @return array<int, string>
     */
    public static function hoaReminderVariants(string $firstName, int|string $ref, ?string $summary, string $link): array
    {
        $name = trim($firstName);
        $greeting = $name !== '' ? "Hi {$name}, " : 'Hi, ';
        $about = filled($summary) ? " regarding {$summary}" : '';

        return [
            "{$greeting}just checking in from TexasRenters.com Maintenance about the HOA notice for your home (WO#{$ref}){$about}. Whenever you get a chance, please take care of it and send us a quick photo as proof using this secure link — no login needed: {$link}. Thanks so much for your help!",
            "{$greeting}following up from TexasRenters.com Maintenance on the HOA notice for your home (WO#{$ref}){$about}. If it works for you, go ahead and handle it, then snap a photo through this link so we can confirm it — no login needed: {$link}. We really appreciate it!",
            "{$greeting}TexasRenters.com Maintenance here, touching base again about the HOA notice for your home (WO#{$ref}){$about}. Once it's sorted, a quick photo through this secure link lets us close it out: {$link}. Thank you!",
            "{$greeting}checking in once more from TexasRenters.com Maintenance about the HOA notice for your home (WO#{$ref}){$about}. If you've already taken care of it, a quick photo through this secure link is all we need: {$link}. If it hasn't been handled yet, we'll need to send a vendor out to correct it. Thanks for your help!",
            "{$greeting}hope you're doing well — this is TexasRenters.com Maintenance with a last note on the HOA notice for your home (WO#{$ref}){$about}. If it's been taken care of, just send a photo through this secure link and we'll close it out: {$link}. If not, we'll be sending a vendor out to correct it so the notice gets resolved. Much appreciated!",
        ];
    }

    private function hoaViolationSummary(WorkOrder $workOrder): ?string
    {
        return self::violationSummaryFromDescription($workOrder->description);
    }

    /**
     * A short, plain-English summary of the violation for the tenant text so
     * they know exactly what to fix. Prefers the concrete "Items to correct:"
     * list (collapsed inline); if the notice had none, falls back to the AI's
     * lead summary sentence. Returns null when only the generic placeholder is
     * on file. Public + static so the demo seeder builds the identical text.
     */
    public static function violationSummaryFromDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        if ($description === '') {
            return null;
        }

        // The list of items is what the tenant actually needs to act on.
        if (preg_match('/Items to correct:\s*(.+?)(?:\n\s*Notice issued by:|$)/is', $description, $matches)) {
            $items = collect(preg_split('/\n+/', $matches[1]))
                ->map(fn ($line) => trim((string) preg_replace('/^\s*[-*]\s*/', '', $line), " \t.-*"))
                ->filter()
                ->all();

            if ($items !== []) {
                return Str::limit(implode('; ', $items), 260, '...', preserveWords: true);
            }
        }

        // Violations raised directly in PropertyWare arrive as repeated
        // "Inspection Date: ... / Corrective Action: [Topic] - instruction"
        // blocks. The instructions are all the tenant needs to act on, so list
        // them without the inspection dates and topic tags.
        if (preg_match_all('/Corrective Action:\s*(?:\[[^\]]*\]\s*-?\s*)?(.+?)(?=\n\s*(?:Inspection Date:|Corrective Action:)|\z)/is', $description, $matches)) {
            $actions = collect($matches[1])
                ->map(fn ($action) => trim((string) preg_replace('/\s+/', ' ', $action), " \t.-"))
                ->filter()
                ->unique(fn ($action) => Str::lower($action))
                ->values();

            if ($actions->isNotEmpty()) {
                $list = $actions->count() > 1
                    ? $actions->map(fn ($action, $index) => '('.($index + 1).') '.$action)->implode(' ')
                    : $actions->first();

                return Str::limit($list, 260, '...', preserveWords: true);
            }
        }

        // No item list — use the lead summary sentence instead.
        $lead = (string) preg_split('/\n\s*(Items to correct:|Notice issued by:)/i', $description)[0];
        $lead = trim((string) preg_replace('/\s+/', ' ', $lead));

        if ($lead === '' || $lead === HoaNoticeExtractor::FALLBACK_DESCRIPTION) {
            return null;
        }

        return Str::limit($lead, 220, '...', preserveWords: true);
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
