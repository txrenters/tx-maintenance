<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMedia extends Model
{
    protected $table = 'work_order_conversation_medias';

    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'message_id');
    }

    public function getPublicUrlAttribute(): string
    {
        return asset('storage/'.$this->local_path);
    }
}
