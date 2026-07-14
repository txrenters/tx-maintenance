<?php

namespace App\Models;

use App\Models\Scopes\WorkOrderScope;
use Carbon\Carbon;
use Database\Factories\WorkOrderFactory;
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
    /** @use HasFactory<WorkOrderFactory> */
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

    /**
     * The property owner to treat as primary for notifications: the linked owner
     * with the largest ownership stake. This deliberately reads from the
     * work_order_owners pivot (the real property owners) rather than the
     * owner_id column / managed_by relationship, which points at the internal
     * management company (e.g. TexasRenters.com, LLC at 0% ownership).
     */
    public function primaryOwner(): ?Owner
    {
        return $this->owners
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership)
            ->first();
    }

    public function owner(): HasOne
    {
        return $this->hasOne(Owner::class, 'work_order_owners', 'work_order_id', 'owner_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenants::class, 'work_order_tenants', 'work_order_id', 'tenant_id');
    }

    public function vendor()
    {
        return $this->vendors()->first();
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'work_order_vendors', 'work_order_id', 'vendor_id')
            ->using(WorkOrderVendor::class)
            ->withPivot('access_token', 'cost_estimate', 'time_estimate', 'scheduled_end_date')
            ->withTimestamps();
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

    public function vendor_owner_conversation(): HasMany
    {
        return $this->hasMany(Conversation::class)->where('conversation_type', 'vendor_owner');
    }

    public function service_schedules(): HasMany
    {
        return $this->hasMany(ServiceSchedule::class)->orderBy('status', 'ASC');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachments::class, 'work_order_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WorkOrderDocuments::class, 'work_order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function recommendation(): HasOne
    {
        return $this->hasOne(WorkOrderRecommendation::class)->latestOfMany();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id', 'propertyware_id');
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
                    WHEN status = 'pending' THEN 1
                    WHEN status = 'completed' THEN 2
                END
            ")
            ->orderBy('created_at', 'desc');
    }

    public function scopeScoped($query)
    {
        (new WorkOrderScope)->apply($query, $this);

        return $query;
    }

    /**
     * Constrain to work orders the current user is actually tagged on via the
     * work_order_vendors pivot — but only when that user is a vendor. For every
     * other role this is a no-op. Pair it with scoped() on aggregate/list
     * queries so a vendor's figures never include work orders they merely have a
     * task or attachment on (which WorkOrderScope's OR-branches would otherwise
     * surface).
     */
    public function scopeTaggedForVendor($query)
    {
        $user = auth()->user();

        if ($user && $user->hasRole('vendor') && $user->vendor) {
            $query->whereHas('vendors', fn ($q) => $q->where('work_order_vendors.vendor_id', $user->vendor->id));
        }

        return $query;
    }

    public function scopeFilter($query, array $filters)
    {
        $query
            ->when($filters['search'] ?? '', function ($q, $search) {
                $q->whereAny([
                    'work_order_no',
                    'location',
                ], 'LIKE', "%{$search}%");
            })
            ->when($filters['vendor'] ?? '', function ($q, $vendorId) {
                $q->whereHas('vendors', function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                });
            })->when(request()->filled(['start_date', 'end_date']) ?? '', function ($q) {
                $date = request()->only(['start_date', 'end_date']);
                $start_date = Carbon::parse($date['start_date'])->startOfDay();
                $end_date = Carbon::parse($date['end_date'])->endOfDay();

                $q->whereBetween('created_date', [$start_date, $end_date]);
            })
            ->emergencyFilter($filters['emergency'] ?? '');

    }

    /**
     * Filter by emergency classification state. Accepts 'emergency',
     * 'non_emergency', or 'needs_review' (unclassified, awaiting a human
     * or AI decision); any other value leaves the query untouched.
     */
    public function scopeEmergencyFilter($query, ?string $emergency = null)
    {
        $emergency ??= request('emergency');

        return $query->when($emergency, function ($q, $value) {
            match ($value) {
                'emergency' => $q->where('is_emergency', true),
                'non_emergency' => $q->where('is_emergency', false),
                'needs_review' => $q->whereNull('is_emergency'),
                default => $q,
            };
        });
    }

    public function scopeFiltered($query)
    {
        $query->when(request('search'), function ($q, $search) {
            $q->where('work_order_no', $search);
        })
            ->when(request('vendor'), function ($q, $vendorId) {
                $q->whereHas('vendors', function ($q) use ($vendorId) {
                    $q->where('work_order_vendors.vendor_id', $vendorId);
                });
            })
            ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                $date = request()->only(['start_date', 'end_date']);
                $start = Carbon::parse($date['start_date'])->startOfDay();
                $end = Carbon::parse($date['end_date'])->endOfDay();
                $q->whereBetween('created_date', [$start, $end]);
            })
            ->where('status', 'Open');
    }
}
