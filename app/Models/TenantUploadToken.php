<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A magic-link token that lets a tenant open the no-login tenant portal for
 * one work order. `purpose` keeps the table generic: the two original purposes
 * ask the tenant for something specific (easy-fix photos, an HOA correction),
 * while PURPOSE_WORK_ORDER is the general link every automated tenant message
 * carries. All three open the same portal.
 */
class TenantUploadToken extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'hoa_notice_date' => 'date',
        'hoa_deadline_at' => 'datetime',
        'escalation_flagged_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'schedule_followup_excluded_at' => 'datetime',
        'schedule_followup_last_sent_at' => 'datetime',
    ];

    public const PURPOSE_TENANT_EASY_FIX = 'tenant_easy_fix';

    public const PURPOSE_HOA_VIOLATION = 'hoa_violation';

    /**
     * The general-purpose portal link, issued on first use for any work order.
     */
    public const PURPOSE_WORK_ORDER = 'work_order';

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Record that this tenant has engaged — messaged us or uploaded a photo
     * through the portal — so the schedule follow-up stops chasing them.
     */
    public function markResponded(): void
    {
        if ($this->responded_at === null) {
            $this->update(['responded_at' => now()]);
        }
    }
}
