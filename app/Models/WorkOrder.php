<?php

namespace App\Models;

use App\Models\Scopes\WorkOrderScope;
use Carbon\Carbon;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[ScopedBy([WorkOrderScope::class])]
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    protected $table = 'work_orders';

    protected $guarded = [];

    /**
     * The channels whose automated messages a WOC has muted for this work order.
     */
    public const AUTOMATION_CHANNELS = ['tenant', 'owner', 'vendor'];

    /**
     * The category a work order carries when it is an HOA violation rather than
     * a repair request. Not a PropertyWare picklist value — it is only ever set
     * inside this app.
     */
    public const HOA_VIOLATION_CATEGORY = 'HOA Violation';

    protected function casts(): array
    {
        return [
            'paused_automations' => 'array',
            'tenant_contact_followup_last_sent_at' => 'datetime',
            'tenant_contact_followup_excluded_at' => 'datetime',
        ];
    }

    /**
     * Whether automated (non-manual) messages on a given channel are muted for
     * this work order. Manual sends never consult this. Unknown/blank channels
     * are treated as active so a bad value never silences everything.
     */
    public function automationPausedFor(string $channel): bool
    {
        return in_array($channel, $this->paused_automations ?? [], true);
    }

    /**
     * Mute or resume a channel's automated messages for this work order.
     */
    public function setAutomationPaused(string $channel, bool $paused): void
    {
        $channels = collect($this->paused_automations ?? []);

        $channels = $paused
            ? $channels->push($channel)->unique()
            : $channels->reject(fn ($existing) => $existing === $channel);

        $this->paused_automations = $channels->values()->all();
        $this->save();
    }

    /**
     * PropertyWare picklist values sometimes carry stray whitespace (e.g.
     * "Turnover "). MySQL's padded comparisons hide that on the backend, but
     * the frontend's exact string matching does not — the Type dropdown showed
     * blank for such rows — so normalize on every write (imports and UI edits)
     * AND on read, so rows stored before the write guard (or before the trim
     * migration has run) still display cleanly.
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : trim((string) $value),
            set: fn ($value) => $value === null ? null : trim((string) $value),
        );
    }

    public function woc(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service_status(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class, 'service_status_id');
    }

    /**
     * Whether this is a turnover job (vacant property). PropertyWare data is
     * inconsistent about where "Turnover" lives — some work orders carry it as
     * the type, others as the category — so turnover behavior (task workflow,
     * THMP mailbox, no owner notifications) matches on either field.
     */
    public function isTurnover(): bool
    {
        return trim((string) $this->type) === 'Turnover'
            || trim((string) $this->category) === 'Turnover';
    }

    /**
     * Whether the unit is vacant: either the WOC's manual "Vacant" toggle
     * (skip_automated_tasks) or a turnover job. A vacant unit has no tenant, so
     * owner/vendor messages drop the "vendor will contact the tenant" line.
     */
    public function isVacant(): bool
    {
        return (bool) $this->skip_automated_tasks || $this->isTurnover();
    }

    /**
     * Whether the in-house vendor "Texas Home Maintenance Pros" (THMP) is among
     * the assigned vendors. THMP jobs are excluded from tenant-facing
     * automations because THMP messages the tenant manually.
     */
    public function hasThmpVendor(): bool
    {
        return $this->vendors->contains(fn (Vendor $vendor) => $vendor->isThmp());
    }

    public function managed_by(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'property_manager_id');
    }

    /**
     * Concise property identifier for email subjects so staff can tell which
     * property an automated email is about: the requested-by/unit address,
     * else the building address, else the building name (empty if none known).
     */
    public function propertyLabel(): string
    {
        return trim((string) (
            $this->requested_by?->address
            ?: $this->building?->address
            ?: $this->building?->name
            ?: ''
        ));
    }

    /**
     * Prefix an email subject with the property so staff can tell at a glance
     * which property it is about; returns the base subject unchanged when no
     * property reference is known.
     */
    public function subjectWithProperty(string $base): string
    {
        $property = $this->propertyLabel();

        return $property !== '' ? $property.' - '.$base : $base;
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
     * property_manager_id column / managed_by relationship, which points at the internal
     * management company (e.g. TexasRenters.com, LLC at 0% ownership).
     */
    public function primaryOwner(): ?Owner
    {
        return $this->owners
            ->sortBy(fn (Owner $owner): int => $owner->id)
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership)
            ->first();
    }

    /**
     * Every owner on this work order who can be texted — ANY ownership
     * percentage. 0% owners are usually spouses, family members, or the humans
     * behind an LLC that holds the 100% stake (often with no phone of its own),
     * so notifications must include them. Ordered primary-first and
     * de-duplicated by normalized phone number, so two owners sharing one line
     * (e.g. a couple) get a single text.
     *
     * @return Collection<int, Owner>
     */
    public function notifiableOwners(): Collection
    {
        return $this->owners
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership)
            ->filter(fn (Owner $owner): bool => $this->normalizedOwnerPhone($owner) !== null)
            ->unique(fn (Owner $owner): string => (string) $this->normalizedOwnerPhone($owner))
            ->values();
    }

    /**
     * The owner's best phone number in E.164 (+1XXXXXXXXXX); null if unusable.
     */
    public function normalizedOwnerPhone(Owner $owner): ?string
    {
        $raw = filled($owner->mobile) ? $owner->mobile : $owner->phone;
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits !== '' ? '+'.$digits : null;
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

    public function tenantUploadTokens(): HasMany
    {
        return $this->hasMany(TenantUploadToken::class);
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

    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
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

    /**
     * Constrain to HOA-violation work orders. Identity primarily lives on the
     * HOA upload token (purpose = hoa_violation) because the PW type/category
     * are curated picklists that the periodic import overwrites. Work orders
     * categorized "HOA Violation" by hand (or imported that way) never get a
     * token, so match on the category too — otherwise they vanish from the HOA
     * board and get treated as ordinary service requests.
     */
    public function scopeHoaViolations($query)
    {
        $query->where(function ($hoa) {
            $hoa->whereHas('tenantUploadTokens', function ($tokens) {
                $tokens->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION);
            })->orWhere('category', self::HOA_VIOLATION_CATEGORY);
        });

        return $query;
    }

    /**
     * Whether this work order is an HOA violation, without touching the
     * database when the category alone already answers it. Used to keep
     * tenant/owner repair automations off violation notices.
     */
    public function isHoaViolation(): bool
    {
        if (trim((string) $this->category) === self::HOA_VIOLATION_CATEGORY) {
            return true;
        }

        return $this->tenantUploadTokens()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->exists();
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
