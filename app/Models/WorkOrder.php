<?php

namespace App\Models;

use App\Models\Scopes\WorkOrderScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([WorkOrderScope::class])]
class WorkOrder extends Model
{
    /** @use HasFactory<\Database\Factories\WorkOrderFactory> */
    use HasFactory;

    protected $table = 'work_orders';

    protected $guarded = [];

    public function woc(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service_status(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class, 'service_status_id');
    }

    public function managed_by(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function requested_by(): BelongsTo
    {
        return $this->belongsTo(Tenants::class, 'tenant_id');
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'work_order_owners');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenants::class, 'work_order_tenants', 'work_order_id', 'tenant_id');
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class, 'work_order_vendors');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'work_order_vendors')
            ->withPivot('cost_estimate', 'time_estimate', 'scheduled_end_date', 'vendor_id')->withTimestamps();
    }

    public function vendor_notes(): HasMany
    {
        return $this->hasMany(WorkOrderNotes::class, 'work_order_id');
    }

    public function tenant_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type', 'tenant');
    }

    public function owner_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type', 'owner');
    }

    public function vendor_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type', 'vendor');
    }

    public function vendor_tenant_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type', 'vendor_tenant');
    }

    public function service_schedules(): HasMany
    {
        return $this->hasMany(ServiceSchedule::class)->orderBy('status', 'ASC');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachments::class, 'work_order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(WorkOrderNotes::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class)
            ->orderByRaw("
                CASE 
                    WHEN status = 'processing' THEN 1
                    WHEN status = 'pending' THEN 2
                    WHEN status = 'completed' THEN 3
                END
            ")
            ->orderBy('created_at', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query
                ->whereAny([
                    'work_order_no',
                    'location',
                ], 'LIKE', "%{$search}%");
        }

        $query->when(request('search'), function ($q, $search) {
            $q->where('work_order_no', $search);
        })
            ->when(request('vendor'), function ($q, $vendorId) {
                $q->whereHas('vendors', function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                });

            })->when(request(['start_date', 'end_date']), function ($q, $date) {

                $start_date = Carbon::parse($date['start_date'])->startOfDay();
                $end_date = Carbon::parse($date['end_date'])->endOfDay();

                $q->whereBetween('created_date', [$start_date, $end_date]);
            });

    }
}
