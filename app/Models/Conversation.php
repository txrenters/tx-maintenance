<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $table = 'work_order_conversations';

    protected $guarded = [];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
