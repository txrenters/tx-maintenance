<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a Jobber note and the photo it brought onto the work
 * order's Attachments tab.
 *
 * The photo itself is an `attachments` row; this only records which note
 * carried which Jobber file, so the photos can be found again when the note
 * is deleted in Jobber.
 */
class WorkOrderJobberNoteFile extends Model
{
    protected $table = 'work_order_jobber_note_files';

    protected $guarded = [];

    public function note(): BelongsTo
    {
        return $this->belongsTo(WorkOrderJobberNote::class, 'work_order_jobber_note_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachments::class, 'attachment_id');
    }
}
