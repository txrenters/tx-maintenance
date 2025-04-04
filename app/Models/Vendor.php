<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function vendor_notes(): HasMany
    {
        return $this->hasMany(VendorNotes::class);
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'name',
                    'name_on_check',
                    'vendor_type',
                ], 'LIKE', "%{$search}%");
        }

        if (! empty($filter['status'])) {
            $status = $filter['status'];

            if ($status != 'All') {
                $query->where(
                    'is_active', $status == 'Active' ? true : false);
            }
        }
    }
}
