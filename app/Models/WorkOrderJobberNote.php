<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A note read off the Jobber job behind a THMP work order.
 *
 * Read-only on our side: the crew writes these in Jobber, the sync copies
 * them here, and nothing is ever written back. They live apart from
 * work_order_notes so the PropertyWare reconciliation cannot delete them and
 * the PropertyWare push cannot pick them up — see the table's migration.
 */
class WorkOrderJobberNote extends Model
{
    protected $table = 'work_order_jobber_notes';

    protected $guarded = [];

    protected $casts = [
        'pinned' => 'boolean',
        'jobber_created_at' => 'datetime',
        'jobber_last_edited_at' => 'datetime',
    ];

    /**
     * Serialised with every note so the dashboard can sort and show it beside
     * the PropertyWare notes, which append the same key.
     *
     * @var list<string>
     */
    protected $appends = ['added_at'];

    /**
     * When the note was written, as an ISO-8601 UTC instant.
     *
     * Jobber's own createdAt, not this row's, which is only when the sync
     * first copied it here. Matches WorkOrderNotes::addedAt() so one merged
     * list can sort on the single key regardless of which table a note is from.
     */
    protected function addedAt(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->jobber_created_at ?? $this->created_at)?->utc()->toISOString(),
        );
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(WorkOrderJobberNoteFile::class, 'work_order_jobber_note_id');
    }
}
