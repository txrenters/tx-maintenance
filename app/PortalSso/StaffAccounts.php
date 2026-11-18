<?php

namespace App\PortalSso;

use App\Models\User;

/**
 * Who counts as staff in Maintenance (User::STAFF_ROLES). Only staff use the TR Staff
 * Portal; vendors, tenants and owners keep their own logins and magic links untouched.
 */
class StaffAccounts
{
    public static function isStaff(?User $user): bool
    {
        return $user !== null && $user->isStaff();
    }

    /** While the portal is on, staff may only sign in through it (unless the emergency switch is on). */
    public static function mustUsePortal(?User $user): bool
    {
        return config('portal.enabled') && ! config('portal.allow_password_login') && self::isStaff($user);
    }
}
