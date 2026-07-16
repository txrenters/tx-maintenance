<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A magic-link token that lets a tenant open the no-login tenant portal for
 * one work order. `purpose` keeps the table generic (tenant_easy_fix today,
 * HOA violations later).
 */
class TenantUploadToken extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

    public const PURPOSE_TENANT_EASY_FIX = 'tenant_easy_fix';

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
}
