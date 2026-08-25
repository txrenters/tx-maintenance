<?php

namespace App\Models;

use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'custom_fields' => 'array',
        'details_synced_at' => 'datetime',
        'maintenance_spending_limit_amount' => 'decimal:2',
        'maintenance_labor_surcharge_amount' => 'decimal:2',
        'latitude' => 'float',
        'longitude' => 'float',
        'geocoded_at' => 'datetime',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'building_id', 'propertyware_id');
    }
}
