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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(JobberVisit::class, 'jobber_visit_id');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereHas('jobber', function ($q) use ($search) {
                    $q->where('job_number', $search);
                })
                ->orWhereAny([
                    'messages',
                    'receiver_number',
                    'sender_number',
                ], 'LIKE', "%{$search}%");
        }
    }
}
