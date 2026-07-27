<?php

namespace App\Models;

use Database\Factories\OwnerEmailNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OwnerEmailNotification extends Model
{
    /** @use HasFactory<OwnerEmailNotificationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cc' => 'array', 'metadata' => 'array', 'has_attachments' => 'boolean', 'sent_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OwnerEmailAttachment::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
