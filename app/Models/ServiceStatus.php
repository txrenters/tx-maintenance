<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceStatus extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceStatusFactory> */
    use HasFactory;

    protected $table = 'service_status';

    protected $guarded = [];

    public function work_orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->latest('created_date');
    }

    public function work_order(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('work_order', function ($q) use ($search) {
                $q->whereAny(['work_order_no', 'location'], 'LIKE', "%{$search}%");
            });
        });

        $query->when($filters['vendor'] ?? null, function ($query, $search) {
            $query->whereHas('work_orders.vendors', function ($q) use ($search) {
                $q->where('vendors.id', $search); // Explicitly use vendors.id
            });
        });
    }
}
