<?php

namespace App\Models;

use App\Models\Scopes\AttachmentScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ScopedBy([AttachmentScope::class])]
class Attachments extends Model
{
    protected $table = 'attachments';

    protected $guarded = [];

    protected $appends = [
        'attachment_url',
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
}
