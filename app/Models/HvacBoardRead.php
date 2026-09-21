<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user's "I have dealt with this one" marker on a single HVAC work order.
 *
 * Holds the moment the row was dismissed rather than a read/unread flag, so a
 * work order that moves again afterwards reappears without anything else in the
 * app having to know this feature exists.
 */
class HvacBoardRead extends Model
{
    protected $table = 'hvac_board_reads';

    protected $fillable = [
        'user_id',
        'work_order_id',
        'dismissed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_updated_at' => 'datetime',
        ];
    }
}
