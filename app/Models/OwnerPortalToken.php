<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A magic-link token that lets one owner open the no-login owner portal for one
 * work order. Mirrors TenantUploadToken, with the schedule follow-up counters
 * living here so each owner is chased (and stops being chased) independently.
 */
class OwnerPortalToken extends Model
{
    protected $guarded = [];

    protected $casts = [
        'responded_at' => 'datetime',
        'schedule_followup_excluded_at' => 'datetime',
        'schedule_followup_last_sent_at' => 'datetime',
    ];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Record that this owner has replied, so the schedule follow-up stops.
     */
    public function markResponded(): void
    {
        if ($this->responded_at === null) {
            $this->update(['responded_at' => now()]);
        }
    }
}
