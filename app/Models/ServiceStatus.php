<?php

namespace App\Models;

use Database\Factories\ServiceStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceStatus extends Model
{
    /** @use HasFactory<ServiceStatusFactory> */
    use HasFactory;

    protected $table = 'service_status';

    protected $guarded = [];

    protected static function booted(): void
    {
        // WorkOrder names a status move from a per-process copy of this table;
        // any change here must reach it, or a renamed or re-created status is
        // named by its old row.
        $forget = fn () => WorkOrder::forgetServiceStatusNames();

        static::saved($forget);
        static::deleted($forget);
    }

    public function work_orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_status_id', 'id')->latest('created_date');
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
