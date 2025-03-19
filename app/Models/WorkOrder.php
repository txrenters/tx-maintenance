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
        return $this->belongsTo(User::class,'user_id');
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
        return $this->belongsToMany(Tenants::class, 'work_order_tenants', 'work_order_id', 'tenant_id');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'work_order_vendors');
    }

    public function tenant_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type','tenant');
    }

    public function owner_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type','owner');
    }

    public function vendor_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type','vendor_tenant');
    }

    public function service_schedules(): HasMany
    {
        return $this->hasMany(ServiceSchedule::class)->orderBy('status','ASC');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachments::class, 'work_order_id');
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
