<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Serialised with every note so the dashboard can show when it was written.
     *
     * @var list<string>
     */
    protected $appends = ['added_at'];

    /**
     * When the note was written, as an ISO-8601 UTC instant.
     *
     * A note that came from PropertyWare carries PropertyWare's own note date;
     * created_at on that row is only when the sync copied it here. A dashboard
     * note never gets a PropertyWare date (the sync leaves rows with a user
     * alone), so its created_at is the real moment it was added. A blank or
     * unreadable PropertyWare date falls back to created_at rather than failing
     * the whole notes payload.
     */
    protected function addedAt(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! blank($this->date)) {
                    try {
                        return Carbon::parse((string) $this->date)->utc()->toISOString();
                    } catch (\Throwable) {
                        // Not a date PropertyWare should have sent; use the row's own time.
                    }
                }

                return $this->created_at?->utc()->toISOString();
            },
        );
    }

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
