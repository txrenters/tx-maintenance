<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobberTextMessage extends Model
{
    protected $table = 'jobber_text_messages';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function jobber(): BelongsTo
    {
        return $this->belongsTo(Jobber::class);
    }

     public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereHas('jobber',function($q) use ($search){
                    $q->where('job_number', $search);
                })
                ->orWhereAny([
                    'title',
                    'visit_status',
                    'instructions',
                ], 'LIKE', "%{$search}%");
        }
    }
}
