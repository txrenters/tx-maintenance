<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Runtime kill-switch for Jobber's automated outbound messages, flipped from
 * the header toggle on the Jobber pages and consulted by the two Jobber
 * senders (SendJobReminders, SendJobberVendorAssignment).
 *
 * Stored as a JSON array of DISABLED automation keys under one app_settings
 * row — the same shape as work_orders.paused_automations — with the extra
 * MASTER sentinel meaning "all Jobber automation off". Absent row/empty array
 * means everything runs, so the feature ships inert.
 *
 * Semantics: "off" drops messages, it does not queue them. Env gates such as
 * JOBBER_VENDOR_NOTIFY_ENABLED still apply on top — a message goes out only
 * when both the env flag and this runtime toggle allow it. Manual staff sends
 * never consult this.
 */
class JobberAutomationSettings
{
    public const KEY = 'jobber_disabled_automations';

    /**
     * Sentinel entry meaning every Jobber automation is off.
     */
    public const MASTER = 'all';

    /**
     * The toggleable Jobber automations. Keys are the canonical automation
     * keys from AutomatedMessageLogService::AUTOMATIONS (the ledger registry
     * stays the single source of truth for labels).
     *
     * @var list<string>
     */
    public const AUTOMATIONS = [
        'tenant_job_reminder_sms',
        'tenant_job_reminder_email',
        'vendor_jobber_assignment_email',
        'vendor_jobber_assignment_sms',
    ];

    /**
     * The disabled automation keys as stored (may include the MASTER sentinel).
     *
     * @return list<string>
     */
    public static function disabled(): array
    {
        $stored = AppSetting::getValue(self::KEY, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * Whether one automation is currently off, either individually or via the
     * master switch. Unknown keys read as enabled (fails open, like the per-WO
     * toggle).
     */
    public static function isDisabled(string $automation): bool
    {
        $disabled = self::disabled();

        return in_array(self::MASTER, $disabled, true)
            || in_array($automation, $disabled, true);
    }

    public static function masterOff(): bool
    {
        return in_array(self::MASTER, self::disabled(), true);
    }

    /**
     * Turn one automation (or the MASTER sentinel) off or back on.
     */
    public static function set(string $automation, bool $disabled): void
    {
        $current = self::disabled();

        $updated = $disabled
            ? array_values(array_unique([...$current, $automation]))
            : array_values(array_filter($current, fn (string $key): bool => $key !== $automation));

        AppSetting::putValue(self::KEY, $updated);
    }

    /**
     * Automation key => human label for the toggle UI, sourced from the
     * ledger registry.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_intersect_key(
            AutomatedMessageLogService::AUTOMATIONS,
            array_flip(self::AUTOMATIONS),
        );
    }
}
