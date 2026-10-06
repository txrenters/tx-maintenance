<?php

namespace App\Models;

use App\Services\PhoneFormatter;
use Database\Factories\OutsideCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Crystal Creek Air customer: someone whose home is not a Texas Renters
 * property, so nothing about them exists in PropertyWare. The office types
 * the details in when the call comes; the Jobber ids are filled in by the
 * job creation so a repeat caller reuses their Jobber property.
 */
class OutsideCustomer extends Model
{
    /** @use HasFactory<OutsideCustomerFactory> */
    use HasFactory;

    protected $guarded = [];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'outside_customer_id');
    }

    /**
     * "123 Main St, Austin, TX 78701" — what the card and the Jobber
     * instructions show as the address.
     */
    public function oneLineAddress(): string
    {
        $cityLine = trim(implode(' ', array_filter([
            trim((string) $this->state),
            trim((string) $this->postal_code),
        ])));

        return implode(', ', array_filter([
            trim((string) $this->street),
            trim((string) $this->city),
            $cityLine,
        ], fn (string $part) => $part !== ''));
    }

    /** The phone in E.164, or null when none is on file or it is unreadable. */
    public function normalizedPhone(): ?string
    {
        return PhoneFormatter::e164($this->phone);
    }
}
