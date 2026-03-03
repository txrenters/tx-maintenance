<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobberProperty extends Model
{
    protected $table = 'jobber_properties';

    protected $guarded = [];

    protected $appends = ['full_address'];

    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->street,
            $this->city,
            $this->province,
            $this->postal_code,
            $this->country,
        ])->filter()->implode(', ');
    }

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
