<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobberVisit extends Model
{
    protected $table = 'jobber_visits';

    protected $guarded = [];

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'title',
                    'visit_status',
                    'instructions',
                ], 'LIKE', "%{$search}%");
        }
    }
}
