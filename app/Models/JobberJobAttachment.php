<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobberJobAttachment extends Model
{
    protected $table = 'jobber_job_attachments';

    protected $guarded = [];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the stored file is a browser-renderable image.
     */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->filetype, 'image/')
            || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $this->filename);
    }
}
