<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An office note on a Jobber job. Lives here only: nothing is read from or
 * written to Jobber, and unlike a work order note there is no PropertyWare
 * copy to keep in step.
 */
class JobberJobNote extends Model
{
    protected $table = 'jobber_job_notes';

    protected $guarded = [];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
