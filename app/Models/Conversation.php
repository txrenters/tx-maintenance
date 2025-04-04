<?php

namespace App\Models;

use App\Models\Scopes\ConversationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([ConversationScope::class])]
class Conversation extends Model
{
    protected $table = 'work_order_conversations';

    protected $guarded = [];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ConversationMedia::class, 'message_id');
    }
}
