<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

/**
 * A vendor assignment on a Jobber job, mirroring {@see WorkOrderVendor}.
 */
class JobberJobVendor extends Pivot
{
    protected $table = 'jobber_job_vendors';

    public $incrementing = true;

    protected $guarded = [];

    protected $casts = [
        'information_sent_at' => 'datetime',
        'scheduled_end_date' => 'date',
    ];

    /**
     * Generate a random access token that is guaranteed unique across assignments.
     */
    public static function generateUniqueAccessToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('access_token', $token)->exists());

        return $token;
    }
}
