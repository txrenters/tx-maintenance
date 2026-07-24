<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Vendor extends Model
{
    use Notifiable;

    protected $table = 'vendors';

    /**
     * The exact name of the in-house vendor "Texas Home Maintenance Pros"
     * (THMP). Matched by name so it works regardless of the record's id in any
     * environment, mirroring how the Jobber integration detects THMP jobs.
     */
    public const THMP_NAME = 'Texas Home Maintenance Pros';

    protected $fillable = [
        'propertyware_id', 'name', 'email', 'name_on_check', 'vendor_type', 'twilio_number', 'is_active', 'user_id', 'zones', 'portal_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'zones' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when this record is the "OWNER VENDOR" placeholder, meaning the
     * property owner handles the repair themselves. No automated vendor or
     * owner texts should be sent for assignments to it. Matched by name so it
     * works regardless of the record's id in any environment.
     */
    public function isOwnerPlaceholder(): bool
    {
        return Str::lower(trim((string) $this->name)) === 'owner vendor';
    }

    /**
     * True when this is the in-house vendor "Texas Home Maintenance Pros".
     * THMP does not reach out to the tenant to schedule the way a third-party
     * vendor does, so tenant-facing automations (assignment notice, the daily
     * "has the vendor reached out?" follow-up) skip it — THMP staff message the
     * tenant manually instead.
     */
    public function isThmp(): bool
    {
        return Str::lower(trim((string) $this->name)) === Str::lower(self::THMP_NAME);
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_vendors', 'vendor_id', 'work_order_id')
            ->withPivot('access_token', 'cost_estimate', 'time_estimate', 'scheduled_end_date')
            ->withTimestamps();
    }

    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Return this vendor's stable no-login dashboard token, creating it if absent.
     */
    public function ensurePortalToken(): string
    {
        if (! $this->portal_token) {
            $this->portal_token = static::generateUniquePortalToken();
            $this->save();
        }

        return $this->portal_token;
    }

    /**
     * Generate a random dashboard token that is guaranteed unique across vendors.
     */
    public static function generateUniquePortalToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('portal_token', $token)->exists());

        return $token;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function vendor_notes(): HasMany
    {
        return $this->hasMany(WorkOrderNotes::class);
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'name',
                    'email',
                    'name_on_check',
                    'vendor_type',
                ], 'LIKE', "%{$search}%");
        }

        if (! empty($filter['status'])) {
            $status = $filter['status'];

            if ($status != 'All') {
                $query->where(
                    'is_active', $status == 'Active' ? true : false);
            }
        }
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }
}
