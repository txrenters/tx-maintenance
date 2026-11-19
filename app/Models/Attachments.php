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

    /**
     * The parts of a home a vendor can file a turnover "after" photo under,
     * in the order the office walks the property. The vendor portal offers
     * exactly this list; keep resources/js/utils/turnoverAreas.js in step.
     *
     * @var list<string>
     */
    public const TURNOVER_AREAS = [
        'Entrance',
        'Living Room',
        'Dining Room',
        'Kitchen',
        'Bedroom 1',
        'Bedroom 2',
        'Bedroom 3',
        'Bedroom 4',
        'Bathroom 1',
        'Bathroom 2',
        'Bathroom 3',
        'Bathroom 4',
        'Office Room',
        'Game Room',
        'Laundry Room',
        'Hallway',
        'Garage',
        'Carpet Cleaning',
        'Lawn',
        'Window Glass Replacement',
        'Haul Away',
    ];

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
