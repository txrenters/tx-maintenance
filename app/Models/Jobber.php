<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jobber extends Model
{
    protected $table = 'jobber_jobs';

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(JobberClient::class, 'jobber_client_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(JobberProperty::class, 'jobber_property_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(JobberVisit::class, 'jobber_job_id');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'filename',
                    'status',
                    'amount',
                ], 'LIKE', "%{$search}%");
        }
    }
}
