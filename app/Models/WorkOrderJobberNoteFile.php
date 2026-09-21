<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to a Jobber note, downloaded onto the public disk.
 *
 * `filename` is the relative key on that disk and stays null until the
 * download succeeds, so a file Jobber is still processing is simply retried
 * on the next sync instead of being shown as a broken image.
 */
class WorkOrderJobberNoteFile extends Model
{
    protected $table = 'work_order_jobber_note_files';

    protected $guarded = [];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(WorkOrderJobberNote::class, 'work_order_jobber_note_id');
    }

    /**
     * Whether this is something the dashboard can show inline. Mirrors
     * JobberJobAttachment::isImage(): Jobber does not always send a content
     * type, so the extension is a second chance rather than a guess.
     */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->content_type, 'image/')
            || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $this->file_name);
    }

    /**
     * Where the stored file is served from, or null while it is still to be
     * downloaded.
     */
    public function url(): ?string
    {
        return blank($this->filename) ? null : asset('storage/'.$this->filename);
    }
}
