<?php

namespace App\Models;

use Database\Factories\OwnerEmailAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerEmailAttachment extends Model
{
    /** @use HasFactory<OwnerEmailAttachmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(OwnerEmailNotification::class, 'owner_email_notification_id');
    }
}
