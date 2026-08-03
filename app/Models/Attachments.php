<?php

namespace App\Models;

use App\Models\Scopes\AttachmentScope;
use App\Observers\ThumbnailObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([ThumbnailObserver::class])]
#[ScopedBy([AttachmentScope::class])]
class Attachments extends Model
{
    protected $table = 'attachments';

    protected $guarded = [];

    protected $appends = [
        'attachment_url',
        'thumbnail_url',
    ];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAttachmentUrlAttribute()
    {
        return asset('storage/'.$this->filename);
    }

    /**
     * Small WebP preview for grid tiles. Falls back to the full-size original so
     * callers never need to branch: rows uploaded before thumbnails existed, and
     * files GD cannot process, keep working unchanged.
     */
    public function getThumbnailUrlAttribute(): string
    {
        return $this->thumb_path
            ? asset('storage/'.$this->thumb_path)
            : $this->attachment_url;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->filetype, 'image/');
    }
}
