<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class ConversationMedia extends Model
{
    protected $table = 'work_order_conversation_medias';

    protected $guarded = [];

    protected $appends = [
        'public_url',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'message_id');
    }

    public function getPublicUrlAttribute(): string
    {
        return URL::signedRoute(
            'conversation.media.show',
            ['media' => $this->id]
        );
    }
}
