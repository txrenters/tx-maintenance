<?php

namespace App\PortalSso;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Maps a portal identity onto an existing Maintenance STAFF account, keeping its id,
 * roles and data. Accounts are never created here (owner decision): an admin adds the
 * person in Maintenance first. Vendor, tenant and owner accounts are never linked.
 */
class PortalUserLinker
{
    /**
     * @param  array<string, mixed>  $claims  userinfo claims (sub, email, email_verified, name)
     *
     * @throws PortalLinkConflict
     * @throws PortalAccessRefused
     */
    public function link(array $claims): User
    {
        $sub = (string) $claims['sub'];
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));

        if ($user = User::where('portal_subject', $sub)->first()) {
            if (! StaffAccounts::isStaff($user)) {
                Log::warning('Portal sign-in refused: linked account is no longer staff', ['user_id' => $user->id]);

                throw new PortalLinkConflict('Your Maintenance account is no longer a staff account. Ask an admin.');
            }

            return $user;
        }

        // Only a verified company email may be used to match an account.
        $domain = Str::after($email, '@');
        if ($email === '' || ($claims['email_verified'] ?? false) !== true || ! in_array($domain, config('portal.allowed_domains'), true)) {
            Log::warning('Portal sign-in refused: email not verified or not a company address', ['email' => $email]);

            throw new PortalLinkConflict('Your portal account has no verified company email. Ask IT.');
        }

        $matches = User::whereRaw('lower(email) = ?', [$email])->get();

        // The database collation may treat different addresses as equal (MySQL: "josé" = "jose").
        if ($matches->contains(fn (User $user) => Str::lower($user->email) !== $email)) {
            Log::warning('Portal sign-in refused: email only matches a lookalike address', ['email' => $email]);

            throw new PortalLinkConflict('Another account here has an address that looks like yours. Ask an admin.');
        }

        $existing = $matches->first();

        if ($existing === null) {
            Log::info('Portal sign-in refused: no Maintenance account for this person yet', ['email' => $email]);

            throw new PortalAccessRefused("You don't have a Maintenance account yet. Ask an admin to add you, then try again.");
        }

        if (! StaffAccounts::isStaff($existing)) {
            Log::warning('Portal sign-in refused: this address belongs to a non-staff account', ['email' => $email]);

            throw new PortalAccessRefused('This address belongs to a vendor, tenant or owner account, not staff. Ask an admin.');
        }

        if ($existing->portal_subject !== null) {
            Log::warning('Portal sign-in refused: email already linked to another portal person', ['email' => $email]);

            throw new PortalLinkConflict('This Maintenance account is linked to a different portal account. Ask IT.');
        }

        $existing->forceFill(['portal_subject' => $sub])->save();

        return $existing;
    }
}
