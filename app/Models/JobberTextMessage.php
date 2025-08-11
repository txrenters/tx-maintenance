<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobberTextMessage extends Model
{
    protected $table = 'jobber_text_messages';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function jobber(): BelongsTo
    {
        return $this->belongsTo(Jobber::class);
    }
}
