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
}
