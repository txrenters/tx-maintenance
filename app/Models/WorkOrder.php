<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    /** @use HasFactory<\Database\Factories\WorkOrderFactory> */
    use HasFactory;

    protected $table = 'work_orders';

    protected $guarded = [];

    public function woc(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service_status(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class,'service_status_id');
    }

    public function managed_by(): BelongsTo
    {
        return $this->belongsTo(Owner::class,'owner_id');
    }

    public function requested_by(): BelongsTo
    {
        return $this->belongsTo(Tenants::class,'tenant_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
    
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'work_order_owners');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenants::class, 'work_order_tenants');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'work_order_vendors');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class)
            ->orderByRaw("
                CASE 
                    WHEN status = 'pending' THEN 1
                    WHEN status = 'processing' THEN 2
                    WHEN status = 'completed' THEN 3
                END
            ")
            ->orderBy('created_at', 'desc');
    }


    public function scopeFilter($query, array $filters)
    {
        if(!empty($filters['search'])){
            $search = $filters['search'];

            $query
            ->whereAny([
                'work_order_no',
                'location',
                ], 'LIKE', "%{$search}%");
        }
    }
}
