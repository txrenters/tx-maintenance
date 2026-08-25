<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderNotes extends Model
{
    protected $table = 'work_order_notes';

    protected $guarded = [];

    protected $casts = [
        'is_private' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Notes the given user may read. Staff and vendors see everything; anyone
     * else (owner and tenant logins) only sees notes PropertyWare would also
     * show on its portals, i.e. the non-private ones. Fails closed for a user
     * with no role at all.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->hasAnyRole(['admin', 'woc', 'accounting', 'vendor'])) {
            return $query;
        }

        return $query->where('is_private', false);
    }
}
