<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobberClient extends Model
{
    protected $table = 'jobber_clients';

    protected $guarded = [];

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'first_name',
                    'last_name',
                    'company_name',
                ], 'LIKE', "%{$search}%");
        }
    }
}
