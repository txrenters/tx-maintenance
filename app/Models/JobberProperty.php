<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobberProperty extends Model
{
    protected $table = 'jobber_properties';

    protected $guarded = [];

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'street',
                    'city',
                    'province',
                    'postal_code',
                    'country',
                ], 'LIKE', "%{$search}%");
        }
    }
}
