<?php

namespace App\Models;

use Database\Factories\TenantEmailNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantEmailNotification extends Model
{
    /** @use HasFactory<TenantEmailNotificationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'has_attachments' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenants::class);
    }

    public function jobberJob(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TenantEmailAttachment::class);
    }
}
