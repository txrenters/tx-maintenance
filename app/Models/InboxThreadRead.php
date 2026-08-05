<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staff user's read marker for one Inbox thread: the highest conversation id
 * they had on screen the last time they opened it. A thread is "unread" for a
 * user when its newest inbound message has a higher id than their marker.
 */
class InboxThreadRead extends Model
{
    protected $fillable = [
        'user_id',
        'work_order_id',
        'conversation_type',
        'vendor_id',
        'owner_id',
        'last_read_conversation_id',
    ];
}
