<?php

namespace App\Models;

use Database\Factories\TenantEmailAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantEmailAttachment extends Model
{
    /** @use HasFactory<TenantEmailAttachmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(TenantEmailNotification::class, 'tenant_email_notification_id');
    }
}
