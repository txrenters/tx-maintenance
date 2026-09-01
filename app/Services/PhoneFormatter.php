<?php

namespace App\Services;

/**
 * Human-readable phone numbers, e.g. "(512) 555-1234".
 *
 * Numbers are stored E.164 ("+15125551234") by the Tenants accessors, which is
 * what Twilio wants but not what a vendor should be reading off a screen. The
 * work order information PDF and the vendor portal both display the same tenant
 * numbers, so they format them the same way here.
 */
class PhoneFormatter
{
    /**
     * Format a US number for display, or return the original value when it is
     * not a recognizable 10-digit number. Null/blank in, null out.
     */
    public static function display(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        return filled($value) ? $value : null;
    }

    /**
     * Format a number the way Twilio wants it ("+13464122380"). PropertyWare
     * hands out bare 10-digit US numbers like "(346) 412-2380"; prefixing "+"
     * without the country code makes Twilio read "+34..." as Spain, so the
     * "1" is assumed here. Anything under 10 digits is not a sendable number
     * and comes back null.
     */
    public static function e164(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return strlen($digits) >= 10 ? '+'.$digits : null;
    }
}
