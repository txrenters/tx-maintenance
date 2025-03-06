<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Vendor extends Model
{
    protected $table = 'vendors';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work_orders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_vendors');
    }

    public function scopeFilter($query, array $filter): void
    {
        if(!empty($filter['search'])){
            $search = $filter['search'];

            $query
            ->whereAny([
                'name',
                'name_on_check',
                'vendor_type',
                ], 'LIKE', "%{$search}%");
        }
    }
}
