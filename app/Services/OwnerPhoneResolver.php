<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks the phone number an owner row should carry after a PropertyWare sync.
 *
 * The portfolio owner PropertyWare returns on a work order often has every
 * phone field blank while the owner's contact record holds the number (WO
 * #43819: blank owner, contact home phone set). The importers used to store
 * only the owner's `phone`, overwriting the row with null on every sync.
 */
class OwnerPhoneResolver
{
    /**
     * Owner fields on the SOAP work order's portfolio, most textable first.
     *
     * @var list<string>
     */
    private const OWNER_FIELDS = ['mobile', 'phone', 'homePhone', 'workTelePhone', 'altPhone'];

    /**
     * Order: the owner's own fields, then the number already stored (so a sync
     * never wipes one found earlier), then the PropertyWare contact.
     *
     * @param  array<string, mixed>  $pwOwner
     */
    public function resolve(array $pwOwner): ?string
    {
        foreach (self::OWNER_FIELDS as $field) {
            if (filled($pwOwner[$field] ?? null)) {
                return trim((string) $pwOwner[$field]);
            }
        }

        if (filled($pwOwner['ID'] ?? null)) {
            $stored = DB::table('owners')->where('propertyware_id', $pwOwner['ID'])->value('phone');

            if (filled($stored)) {
                return $stored;
            }
        }

        $contactId = $pwOwner['contactId'] ?? $pwOwner['ID'] ?? null;

        if (blank($contactId)) {
            return null;
        }

        try {
            return app(PropertyWareService::class)->getContactPhone($contactId);
        } catch (Throwable $e) {
            Log::warning('Owner contact phone lookup failed: '.$e->getMessage(), ['contact_id' => $contactId]);

            return null;
        }
    }
}
