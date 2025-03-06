<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceStatus extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceStatusFactory> */
    use HasFactory;

    protected $table = 'service_status';

    protected $guarded = [];

    public function work_orders() : HasMany
    {
        return $this->hasMany(WorkOrder::class)->latest('created_date');
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%');
        });
    }
    
}
