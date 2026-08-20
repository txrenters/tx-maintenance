<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobberVisit extends Model
{
    protected $table = 'jobber_visits';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_complete' => 'boolean',
            'notified_14_days' => 'boolean',
            'notified_7_days' => 'boolean',
            'notified_3_days' => 'boolean',
            'notified_1_days' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereHas('job', function ($q) use ($search) {
                    $q->where('job_number', $search);
                })
                ->orWhereAny([
                    'title',
                    'visit_status',
                    'instructions',
                ], 'LIKE', "%{$search}%");
        }
    }

    public function scopeTbp($query): void
    {
        $query->where(function ($q) {
            $q->whereRaw('LOWER(title) LIKE ?', ['%tenant benefit%'])
                ->orWhereRaw('LOWER(title) LIKE ?', ['%tbp%']);
        });

    }
}
