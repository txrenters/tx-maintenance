<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    /** @use HasFactory<\Database\Factories\BuildingFactory> */
    use HasFactory;

    protected $guarded = [];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'building_id', 'propertyware_id');
    }
}
