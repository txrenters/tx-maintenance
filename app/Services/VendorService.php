<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorService
{
    /**
     * Create or update a vendor (and its linked user) from PropertyWare data.
     *
     * Keyed on the PropertyWare vendor ID so re-importing syncs instead of
     * duplicating. The linked user is matched by email and updated in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle($data): ?Vendor
    {
        $pwId = $data['id'] ?? $data['ID'] ?? null;

        if (! $pwId) {
            Log::warning('Vendor sync skipped: missing PropertyWare ID.');

            return null;
        }

        DB::beginTransaction();

        try {
            $name = $data['name'] ?? $data['companyName'] ?? 'Unknown Vendor';
            $email = $this->resolveEmail($data, $pwId, $name);

            $address = trim(implode(' ', array_filter([
                $data['address'] ?? null,
                $data['address2'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['country'] ?? null,
                $data['zip'] ?? null,
            ])));

            // Prefer the vendor's already-linked user so a re-sync corrects their
            // existing record (e.g. fixes a bad email) instead of orphaning it and
            // creating a new one — unless that user is the shared blank-email row
            // the imports used to park every e-mail-less vendor on (see
            // Vendor::userEmailFor()): renaming that one would rename it for all
            // of them, so such a vendor gets its own user instead. Fall back to
            // matching by email, then creating.
            $existingVendor = Vendor::where('propertyware_id', $pwId)->first();
            $linkedUser = $existingVendor?->user;
            $user = $linkedUser && filled($linkedUser->email)
                ? $linkedUser
                : User::firstOrNew(['email' => $email]);

            $user->email = $email;
            $user->name = $name;
            $user->phone = $data['phone'] ?? $data['otherPhone'] ?? $user->phone;
            $user->company = $data['companyName'] ?? $user->company;
            $user->address = $address ?: $user->address;
            // Keep the password in sync with the email (this app uses email-as-password).
            $user->password = bcrypt($email);
            $user->save();

            if (! $user->hasRole('vendor')) {
                $user->assignRole('vendor');
            }

            $vendor = Vendor::updateOrCreate(
                ['propertyware_id' => $pwId],
                [
                    'name' => $name,
                    'name_on_check' => $data['nameOnCheck'] ?? $name,
                    // The placeholder address keys the user only; the vendor
                    // record keeps no e-mail, so nothing tries to write to it.
                    'email' => ! empty($data['email']) ? $data['email'] : null,
                    'vendor_type' => $data['type'] ?? null,
                    'is_active' => $data['active'] ?? true,
                    'user_id' => $user->id,
                ]
            );

            DB::commit();

            return $vendor;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error syncing vendor: '.$e->getMessage(), ['propertyware_id' => $pwId]);

            return null;
        }
    }

    /**
     * Resolve a vendor's email, falling back to a readable placeholder when
     * PropertyWare has none on file (instead of an opaque numeric address).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveEmail(array $data, $pwId, string $name): string
    {
        if (! empty($data['email'])) {
            return $data['email'];
        }

        $slug = Str::slug($name) ?: 'vendor';

        return "{$slug}-{$pwId}@no-email.texasrenters.com";
    }
}
