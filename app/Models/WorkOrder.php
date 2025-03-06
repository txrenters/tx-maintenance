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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class,'owner_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenants::class);
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

    // public function owner(): HasMany
    // {
    //     return $this->hasMany(Owner::class);
    // }
}
