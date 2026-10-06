<?php

namespace App\Models;

use App\Models\Scopes\WorkOrderScope;
use App\Services\PhoneFormatter;
use App\Services\PropertyWareService;
use App\Services\TenantEasyFixService;
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
use Illuminate\Support\Facades\DB;

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

    /**
     * The `source` of a work order for a Crystal Creek Air customer: someone
     * whose home is not a Texas Renters property. These rows have no
     * PropertyWare record (propertyware_id null, their own number series),
     * are worked by the THMP crew through Jobber, and live on their own board
     * only — every other board excludes them through notCrystalCreek().
     */
    public const CRYSTAL_CREEK_SOURCE = 'Crystal Creek Air';

    /**
     * PropertyWare "Source" values that mean the tenant raised the work order
     * themselves: the tenant portal and the public website form. Every other
     * value PropertyWare emits ("None" when staff leave the picklist unset,
     * "Telephone", "Inspection", "Internal", "Email", "In Person", ...) is a
     * work order someone on our team typed in.
     */
    public const TENANT_ORIGIN_SOURCES = ['Tenant Portal', 'Website'];

    /**
     * The kanban boards a work order can appear on, keyed by the value the
     * summary endpoint accepts. Each maps to one of the WorkOrderController
     * board methods; scopeForBoard() reproduces that method's predicate.
     *
     * @var array<int, string>
     */
    public const BOARDS = [
        'main',
        'inspections',
        'lawn_service',
        'turnovers',
        'closed',
        'waiting_on_payment',
        'paid',
        'hoa',
        'hvac',
        'easy_fix',
        'crystal_creek',
    ];

    /**
     * The statuses that mean nobody is waiting on us any more. The inverse of
     * the 'closed' board branch below; consumers filtering on this should stay
     * NULL-safe so a status-less row keeps counting rather than going silent.
     */
    public const CLOSED_STATUSES = ['Closed', 'Canceled By Tenant'];

    /**
     * The service status whose column the Waiting on Payment board shows.
     */
    public const WAITING_ON_PAYMENT_STATUS = 'Approved - Waiting on Payment';

    /**
     * How far back the Paid and Closed buckets reach. Mirrors the 30-day window
     * WorkOrderController applies to those columns.
     */
    public const COMPLETED_WINDOW_DAYS = 30;

    protected function casts(): array
    {
        return [
            'paused_automations' => 'array',
            'tenant_contact_followup_last_sent_at' => 'datetime',
            'tenant_contact_followup_excluded_at' => 'datetime',
        ];
    }

    /**
     * The location to store for an imported work order.
     *
     * PropertyWare's SOAP API holds "PORTFOLIO | BUILDING" and validates every
     * update against it, but its REST work order hands the same string back
     * with the pipe collapsed to a space. Storing the REST copy made the next
     * vendor push fail with "Location is invalid" (WO#40363), so a value in
     * PropertyWare's own format is never replaced by one without it.
     */
    public static function importedLocation(mixed $incoming, mixed $current): ?string
    {
        $incoming = trim((string) ($incoming ?? ''));
        $current = trim((string) ($current ?? ''));

        if ($incoming === '') {
            return $current === '' ? null : $current;
        }

        if (PropertyWareService::looksLikePropertyWareLocation($current)
            && ! PropertyWareService::looksLikePropertyWareLocation($incoming)) {
            return $current;
        }

        return $incoming;
    }

    /**
     * Resolve the completed_date an import may write for a work order.
     *
     * PropertyWare frequently reports Closed work orders with no Completed
     * Date. Writing the payload value verbatim nulls the date on every sync,
     * so ~1,200 historical closed work orders can never keep a date — and the
     * closed board's fallback window then mistook every bulk-sync touch for a
     * fresh close, flooding the column with years-old cards (2026-08-22).
     *
     * The rules, in order: a payload date always wins; a non-Closed status
     * clears the date (a reopened work order is not completed); an existing
     * date is preserved; a work order first seen transitioning to Closed is
     * stamped today (the WO#42487 case — closed in PropertyWare with no
     * date); one that was already Closed with no date (historical backlog,
     * or arriving already Closed on first import) is dated by its creation
     * so it ages out of the board window instead of resurfacing as new.
     */
    public static function resolveImportCompletedDate(
        ?string $payloadDate,
        ?string $incomingStatus,
        ?string $existingStatus,
        ?string $existingCompletedDate,
        mixed $createdDate,
    ): ?string {
        if ($payloadDate !== null) {
            return $payloadDate;
        }

        if ($incomingStatus !== 'Closed') {
            return null;
        }

        if ($existingCompletedDate !== null) {
            return Carbon::parse($existingCompletedDate)->toDateString();
        }

        if ($existingStatus !== null && $existingStatus !== 'Closed') {
            return now()->toDateString();
        }

        return $createdDate ? Carbon::parse((string) $createdDate)->toDateString() : null;
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
     * The fields worth naming when they change, as column => phrase. Order
     * matters: the first match wins, so the status move — the thing that
     * actually moves a card between columns — is named ahead of an edit that
     * happened in the same save.
     *
     * @var array<string, string>
     */
    private const CHANGE_LABELS = [
        'is_emergency' => 'emergency flag changed',
        'priority' => 'priority changed',
        'scheduled_end_date' => 'schedule changed',
        'start_date' => 'schedule changed',
        'total_cost' => 'cost updated',
        'cost_estimate' => 'estimate updated',
        'category' => 'category changed',
        'type' => 'type changed',
        'is_approved' => 'approval changed',
        'description' => 'description edited',
    ];

    protected static function booted(): void
    {
        // Record what changed, in words, so the HVAC board's "what moved" list
        // can say "moved to Scheduled" instead of the generic "updated".
        //
        // A model hook rather than a line in each caller: the status is written
        // from controllers, services, jobs and PropertyWare sync alike, and one
        // missed caller would silently go back to saying "updated". This is a
        // string assignment on a save that is already happening — no query.
        static::saving(function (self $workOrder): void {
            if (! $workOrder->exists) {
                return;
            }

            $summary = $workOrder->describeOwnChanges();

            if ($summary !== null) {
                $workOrder->last_change_summary = $summary;
            }
        });
    }

    /**
     * A short phrase for the change about to be saved, or null when nothing
     * worth naming changed.
     */
    /**
     * Status id => name, resolved once per request.
     *
     * This hook runs on every work order save in the application, and the
     * PropertyWare sync saves them in bulk — so a per-save SELECT here would
     * add one query per status change across an import. The table is ~21
     * near-static rows, so it is read once and held for the process.
     *
     * @var array<int, string>|null
     */
    private static ?array $serviceStatusNames = null;

    private function describeOwnChanges(): ?string
    {
        if ($this->isDirty('service_status_id')) {
            $name = self::serviceStatusName($this->service_status_id);

            return $name ? 'moved to '.$name : 'status changed';
        }

        foreach (self::CHANGE_LABELS as $column => $label) {
            if ($this->isDirty($column)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The name of a service status, from the per-process cache.
     *
     * Returns null rather than throwing if the lookup fails: this runs inside
     * every work order save, and a cosmetic label for one board must never be
     * the reason a save fails. The caller degrades to "status changed".
     */
    private static function serviceStatusName(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;

        if (self::$serviceStatusNames === null) {
            self::$serviceStatusNames = self::loadServiceStatusNames();
        }

        if (isset(self::$serviceStatusNames[$id])) {
            return self::$serviceStatusNames[$id];
        }

        // An id the cache has never seen: a status added through the admin
        // page after a long-lived queue worker filled this. Refresh once so the
        // worker picks it up without a restart. A genuinely unknown id (a
        // deleted status) re-reads at most once per save, which only happens on
        // a status change, so it cannot become a hot path.
        self::$serviceStatusNames = self::loadServiceStatusNames();

        return self::$serviceStatusNames[$id] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private static function loadServiceStatusNames(): array
    {
        try {
            return ServiceStatus::query()->pluck('name', 'id')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Drop the cached status names.
     *
     * Only needed where statuses are created mid-process — chiefly tests, which
     * build them with factories after this cache may already have been filled.
     */
    public static function forgetServiceStatusNames(): void
    {
        self::$serviceStatusNames = null;
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
     * Whether this work order's type or category matches one of the given
     * pre-normalized values. Compared on letters and digits only, because
     * PropertyWare varies hyphens, casing, and spacing ("Re-key" vs "Re-Key",
     * the "HVAC " trailing space) between picklists.
     *
     * @param  array<int, string>  $normalizedValues
     */
    private function typeOrCategoryMatches(array $normalizedValues): bool
    {
        $normalize = fn (?string $value): string => preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));

        return in_array($normalize($this->type), $normalizedValues, true)
            || in_array($normalize($this->category), $normalizedValues, true);
    }

    /**
     * Whether this is a re-key job — vendor-managed (Express Key) lock work
     * between tenants.
     */
    public function isRekey(): bool
    {
        return $this->typeOrCategoryMatches(['rekey']);
    }

    /**
     * Whether this is a refresh / professional-cleaning job the company orders
     * itself: the "Cleaning", "Make ready", and "carpet Steam clean"
     * categories. These may happen in an occupied home, so they are opted out
     * of automated messages without being treated as vacant.
     */
    public function isRefreshCleaning(): bool
    {
        return $this->typeOrCategoryMatches(['cleaning', 'makeready', 'carpetsteamclean']);
    }

    /**
     * Whether automated tenant/owner messages must stay silent for this work
     * order: vacant homes (turnover, re-key, the manual toggle) plus
     * company-ordered refresh/cleaning jobs and PropertyWare work orders whose
     * property holds no active lease (new-to-market and between-tenant homes).
     * Broader than isVacant() on purpose — a cleaning can happen while a
     * tenant lives there, so vendors still get the tenant's contact info; only
     * the automated messages stop.
     */
    public function skipsAutomatedMessages(): bool
    {
        return $this->isVacant() || $this->isRefreshCleaning() || $this->hasNoLeaseOnFile();
    }

    /**
     * Whether PropertyWare reported no active lease for this work order's
     * property. PropertyWare attaches the property's current lease (and its
     * tenant roster) to every work order it returns for an occupied home, so
     * an imported row with neither belongs to a vacant / new-to-market
     * property — its requestedByContact is a leasing agent, staff member, or
     * former occupant, not a tenant awaiting repairs (see WO#43485).
     *
     * Rows the app creates itself never carry a lease, so they are exempt:
     * local-only rows have no propertyware_id, tenant portal requests are
     * stamped source "Tenant Portal" at intake, and HOA violations are
     * recognised by isHoaViolation().
     */
    public function hasNoLeaseOnFile(): bool
    {
        if ($this->propertyware_id === null || $this->lease_id !== null) {
            return false;
        }

        if ($this->source === 'Tenant Portal' || $this->isHoaViolation()) {
            return false;
        }

        return ! $this->tenants()->exists();
    }

    /**
     * Whether someone on our team entered this work order in PropertyWare
     * rather than the tenant submitting it. Read from PropertyWare's Source
     * field, which PropertyWare stamps itself (its createdBy is not mapped for
     * work orders): "Tenant Portal" and "Website" are the tenant's own
     * channels; anything else was typed in by staff after a call, an
     * inspection, a technician find, or an HOA notice. A blank source is
     * unknown (PropertyWare never sends one; local rows may carry none) and
     * keeps the "we received your request" wording rather than claiming our
     * team created it.
     */
    public function isStaffCreated(): bool
    {
        $source = trim((string) $this->source);

        return $source !== '' && ! in_array($source, self::TENANT_ORIGIN_SOURCES, true);
    }

    /**
     * Whether this is a Crystal Creek Air work order: an outside customer's
     * home, never in PropertyWare. Every PropertyWare push and every tenant or
     * owner automation must stand down for these.
     */
    public function isCrystalCreek(): bool
    {
        return trim((string) $this->source) === self::CRYSTAL_CREEK_SOURCE;
    }

    public function outsideCustomer(): BelongsTo
    {
        return $this->belongsTo(OutsideCustomer::class, 'outside_customer_id');
    }

    /** Only Crystal Creek Air work orders. */
    public function scopeCrystalCreek($query)
    {
        return $query->where('source', self::CRYSTAL_CREEK_SOURCE);
    }

    /**
     * Everything except Crystal Creek Air work orders. NULL-safe on purpose:
     * `source <> x` alone drops every imported row whose source PropertyWare
     * left blank.
     */
    public function scopeNotCrystalCreek($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('source')
                ->orWhere('source', '<>', self::CRYSTAL_CREEK_SOURCE);
        });
    }

    /**
     * The import payload columns whose value differs from what this row holds.
     *
     * PropertyWare hands back numbers, dates and flags in a different shape
     * from what the database returns (150 vs "150.00", a Carbon vs its stored
     * string, false vs 0) and Eloquent counts each as a change, so every
     * scheduled sync re-saved every work order: created_at drifted to the last
     * import and updated_at moved every ten minutes, which the HVAC board's
     * "moved since I last looked" counter reads (22 of 22 on 2026-09-22).
     * Writing only these columns leaves an unchanged row untouched and lets a
     * real change bump updated_at and record what moved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function importChanges(array $data): array
    {
        foreach ($data as $column => $incoming) {
            if (self::sameStoredValue($incoming, $this->getRawOriginal($column), in_array($column, self::PICKLIST_COLUMNS, true))) {
                unset($data[$column]);
            }
        }

        return $data;
    }

    /**
     * PropertyWare picklists whose two feeds spell the same entry with
     * different spacing as well as case: SOAP "Any Time", REST "ANYTIME"
     * (WOs #44153, #44229, #44261, #44262, read live from both 2026-10-02).
     * Compared on letters and digits alone.
     *
     * @var array<int, string>
     */
    private const PICKLIST_COLUMNS = ['authorized_to_enter'];

    /**
     * Whether a payload value and a stored value mean the same thing once the
     * payload's shape is normalised to the database's.
     *
     * Text is compared without regard to case or edge whitespace: the SOAP
     * feed spells a priority "Medium" and the REST feed spells the same one
     * "MEDIUM", so the two scheduled syncs otherwise overwrite each other
     * every ten and fifteen minutes and every open work order reads as
     * "priority changed" all day (HVAC board, 2026-09-22). A picklist value
     * that only changed case is not a change anyone needs to see. Likewise
     * line breaks: the REST feed writes a description's "\n" as "\r\n"
     * (WO #44269, 2026-10-02), the same text either way.
     */
    private static function sameStoredValue(mixed $incoming, mixed $stored, bool $picklist = false): bool
    {
        if ($incoming instanceof \DateTimeInterface) {
            $incoming = $incoming->format('Y-m-d H:i:s');
        }

        $blank = fn (mixed $value): bool => $value === null || $value === '';

        // The imports write `false` for a missing text value, which lands in
        // a string column as '' — the same nothing.
        if ($incoming === false && $blank($stored)) {
            return true;
        }

        if (is_bool($incoming)) {
            $incoming = (int) $incoming;
        }

        if (is_bool($stored)) {
            $stored = (int) $stored;
        }

        if ($blank($incoming) || $blank($stored)) {
            return $blank($incoming) && $blank($stored);
        }

        if (is_numeric($incoming) && is_numeric($stored)) {
            return (float) $incoming === (float) $stored;
        }

        $text = fn (mixed $value): string => $picklist
            ? (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $value))
            : trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return strcasecmp($text($incoming), $text($stored)) === 0;
    }

    /**
     * Whether an import payload's Source may replace the one on file. The app
     * stamps "Tenant Portal" itself on the work orders it creates for tenant
     * portal requests (PropertyWare's API create carries no Source, so
     * PropertyWare reports "None" for them); a later import must not wipe
     * that stamp, or the request loses its tenant-origin wording and its
     * exemption from the no-lease skip. A real Source PropertyWare later
     * shows still wins.
     */
    public static function importedSourceReplaces(?string $stored, ?string $incoming): bool
    {
        if (trim((string) $stored) !== 'Tenant Portal') {
            return true;
        }

        $incoming = trim((string) $incoming);

        return $incoming !== '' && $incoming !== 'None';
    }

    /**
     * Whether the unit is vacant: the WOC's manual "Vacant" toggle
     * (skip_automated_tasks), a turnover job, or a re-key job (locks only
     * change hands between tenants). A vacant unit has no tenant, so tenant
     * automation stays silent and owner/vendor messages drop the "vendor will
     * contact the tenant" line. Turnover-only behavior (THMP mailbox, task
     * templates, turnover invoices) keys off isTurnover() instead.
     */
    public function isVacant(): bool
    {
        return (bool) $this->skip_automated_tasks || $this->isTurnover() || $this->isRekey();
    }

    /**
     * The SQL form of hasNoTenant(), for filtering and counting lists without
     * loading every row: the WOC's Vacant toggle, a turnover, a re-key, or no
     * lease on file. Kept in step with hasNoTenant() rather than the narrower
     * isVacant(), because a between-tenant home that carries none of the first
     * three still has nobody living in it (WO#44032).
     *
     * isVacant() compares on letters and digits alone; SQL cannot strip
     * characters the same way, so the spellings PropertyWare actually emits
     * ("Re-key", "Re Key", "ReKey") are matched explicitly. Every comparison is
     * on TRIM(), because PropertyWare's picklists carry trailing spaces
     * ("HVAC ") and isTurnover() trims before comparing. That also keeps the
     * result off the collation: whether ''=' ' is true varies between MySQL 8
     * and older servers.
     */
    public function scopeVacant($query)
    {
        return $query->where(function ($query) {
            $query->where('skip_automated_tasks', true)
                ->orWhereRaw('TRIM(type) = ?', ['Turnover'])
                ->orWhereRaw('TRIM(category) = ?', ['Turnover'])
                ->orWhereRaw('TRIM(type) LIKE ?', ['Re%Key'])
                ->orWhereRaw('TRIM(category) LIKE ?', ['Re%Key'])
                ->orWhere(fn ($query) => $query->withoutLease()
                    ->whereNot(fn ($lease) => $lease->hasActiveLeaseOnFile()));
        });
    }

    /**
     * The complement of scopeVacant(): somebody lives there. A null type or
     * category cannot mark a unit vacant, so those rows stay occupied.
     */
    public function scopeOccupied($query)
    {
        return $query->where(function ($query) {
            $query->where(function ($query) {
                $query->where('skip_automated_tasks', false)
                    ->orWhereNull('skip_automated_tasks');
            })
                ->whereRaw('(TRIM(type) <> ? OR type IS NULL)', ['Turnover'])
                ->whereRaw('(TRIM(category) <> ? OR category IS NULL)', ['Turnover'])
                ->whereRaw('(TRIM(type) NOT LIKE ? OR type IS NULL)', ['Re%Key'])
                ->whereRaw('(TRIM(category) NOT LIKE ? OR category IS NULL)', ['Re%Key'])
                ->whereNot(fn ($query) => $query->withoutLease()
                    ->whereNot(fn ($lease) => $lease->hasActiveLeaseOnFile()));
        });
    }

    /**
     * The SQL form of hasNoLeaseOnFile(). PropertyWare attaches the property's
     * lease and tenant roster to every work order for an occupied home, so a
     * row it sent with neither belongs to a vacant or new-to-market property.
     * Rows the app creates itself never carry a lease, so they are exempt the
     * same way hasNoLeaseOnFile() exempts them.
     */
    public function scopeWithoutLease($query)
    {
        return $query->whereNotNull('propertyware_id')
            ->whereNull('lease_id')
            ->where(fn ($query) => $query->whereNull('source')->orWhere('source', '<>', 'Tenant Portal'))
            ->whereRaw('(TRIM(category) <> ? OR category IS NULL)', [self::HOA_VIOLATION_CATEGORY])
            ->whereDoesntHave('tenantUploadTokens', fn ($tokens) => $tokens->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION))
            ->whereDoesntHave('tenants');
    }

    /**
     * True when PropertyWare currently reports an active lease on the work
     * order's building.
     *
     * withoutLease() infers vacancy from what PropertyWare attached to the work
     * order, which is only ever a statement about that row: a job raised against
     * the property rather than a tenancy (an owner lawn-service quote, WO#43275)
     * carries no lease however occupied the home is, and a row keeps whatever it
     * was sent with months ago. The leases table is the property's status today,
     * so where it has an answer it outranks the guess.
     *
     * Buildings key on the PropertyWare id, matching Lease::building().
     */
    public function scopeHasActiveLeaseOnFile($query)
    {
        return $query->whereNotNull('building_id')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('leases')
                    ->whereColumn('leases.building_id', 'work_orders.building_id')
                    ->whereRaw('LOWER(TRIM(leases.status)) = ?', ['active']);
            });
    }

    /**
     * Whether nobody lives at the property for a vendor to contact: the unit
     * is vacant (isVacant()) or PropertyWare attached no lease to the work
     * order (hasNoLeaseOnFile() — a new-to-market or between-tenant home that
     * carries neither the Vacant toggle nor a turnover/re-key type, WO#44032).
     * The owner vendor-assignment text and email drop their "vendor will
     * contact the tenant" line on these while still telling the owner who
     * was assigned. Vendor-facing output keeps using isVacant().
     */
    public function hasNoTenant(): bool
    {
        return $this->isVacant() || $this->hasNoLeaseOnFile();
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
     * The work order's property reference for messages: the building's street
     * address (synced by sync:building-details and joined on this work order's
     * building_id), else the building's PropertyWare name (e.g. "1532A").
     * The requested-by contact's mailing address is never used: it is a
     * PropertyWare contact field, keyed globally and overwritten on every
     * import, and can point at a different property entirely (WO#43517 told
     * an owner the work was at the incoming tenant's old home address).
     * Null when no building detail is known.
     */
    public function propertyAddress(): ?string
    {
        $buildingAddress = trim((string) ($this->building?->address ?? ''));

        if ($buildingAddress !== '') {
            return $buildingAddress;
        }

        $buildingName = trim((string) ($this->building?->name ?? ''));

        return $buildingName !== '' ? $buildingName : null;
    }

    /**
     * Concise property identifier for email subjects so staff can tell which
     * property an automated email is about (empty if none known).
     */
    public function propertyLabel(): string
    {
        return trim((string) ($this->propertyAddress() ?: ''));
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

    /**
     * The tenants an automated intake message (the request-received or
     * created-by-our-team text and email) should reach, limited to those
     * $reachable can actually deliver to on the channel in question.
     *
     * PropertyWare's "Requested By" contact is whoever raised the work order.
     * On a tenant portal or website request that is the tenant; on a work
     * order our team enters it is whoever staff picked, and on an inspection
     * finding it is routinely the technician who logged it — WO#44111's
     * Requested By was a THMP technician with no phone, so the tenant was
     * never told a work order had been opened for their home. The lease
     * roster PropertyWare attaches (work_order_tenants) is the tenancy
     * itself, so:
     *
     * - no roster (a website request, a local row): the requester, as before;
     * - the requester is on the roster: the requester alone, so a portal
     *   request is answered to the person who sent it rather than the whole
     *   household — unless they cannot be reached, when the rest of the
     *   roster stands in;
     * - the requester is not on the roster (staff, a technician, nobody):
     *   every tenant on the roster.
     *
     * @param  callable(Tenants): bool  $reachable
     * @return Collection<int, Tenants>
     */
    public function tenantIntakeRecipients(callable $reachable): Collection
    {
        $this->loadMissing(['requested_by', 'tenants']);

        $requester = $this->requested_by;
        $roster = $this->tenants;

        if ($requester !== null && ($roster->isEmpty() || $this->requesterIsLeaseTenant()) && $reachable($requester)) {
            return collect([$requester]);
        }

        return $roster
            ->filter(fn (Tenants $tenant): bool => $reachable($tenant))
            ->values();
    }

    /**
     * Whether PropertyWare's Requested By contact is one of the tenants on
     * the lease roster — by row, or by PropertyWare contact id, since the
     * same contact can land in tenants twice (once from requestedByContact,
     * once from the lease).
     */
    public function requesterIsLeaseTenant(): bool
    {
        $this->loadMissing(['requested_by', 'tenants']);

        $requester = $this->requested_by;

        if ($requester === null) {
            return false;
        }

        return $this->tenants->contains(
            fn (Tenants $tenant): bool => $tenant->is($requester)
                || (filled($tenant->propertyware_id) && (string) $tenant->propertyware_id === (string) $requester->propertyware_id)
        );
    }

    /**
     * The tenant's best phone number in E.164 (+1XXXXXXXXXX): mobile first,
     * then home; null if neither is usable.
     */
    public function normalizedTenantPhone(Tenants $tenant): ?string
    {
        return PhoneFormatter::e164(filled($tenant->mobile_phone) ? $tenant->mobile_phone : $tenant->home_phone);
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

    /**
     * Vendors no longer assigned to this work order that still have messages in
     * one of its vendor threads. Removing a vendor only detaches the pivot row
     * — work_order_conversations rows are never deleted — but the conversation
     * UI's vendor picker lists current assignees only, which made a removed
     * vendor's history unreachable. Messages are attributed by their vendor_id
     * tag; untagged rows (inbound replies, pre-tagging history) by phone
     * number, mirroring Conversation::scopeForVendorThread.
     *
     * Caller is responsible for gating: this intentionally reads the whole
     * thread set without the per-role ConversationScope, so only expose the
     * result to staff.
     *
     * @return Collection<int, Vendor>
     */
    public function formerConversationVendors(): Collection
    {
        $assignedIds = $this->vendors()->pluck('vendors.id');

        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $this->id)
            ->whereIn('conversation_type', ['vendor', 'vendor_tenant', 'vendor_owner'])
            ->get(['vendor_id', 'sender_number', 'receiver_number']);

        $taggedIds = $messages->pluck('vendor_id')
            ->filter()
            ->unique()
            ->reject(fn ($id) => $assignedIds->contains($id))
            ->values();

        $numbers = $messages->whereNull('vendor_id')
            ->flatMap(fn (Conversation $message) => [
                Conversation::lastTenDigits($message->sender_number),
                Conversation::lastTenDigits($message->receiver_number),
            ])
            ->filter()
            ->unique()
            ->take(30)
            ->values();

        if ($taggedIds->isEmpty() && $numbers->isEmpty()) {
            return new Collection;
        }

        return Vendor::with('user')
            ->where(function ($query) use ($taggedIds, $numbers) {
                $query->whereIn('id', $taggedIds);

                foreach ($numbers as $digits) {
                    $query->orWhere('twilio_number', 'LIKE', '%'.$digits)
                        ->orWhereHas('user', fn ($user) => $user->where('phone', 'LIKE', '%'.$digits));
                }
            })
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->get()
            ->each(fn (Vendor $vendor) => $vendor->setAttribute('removed_from_work_order', true))
            ->toBase();
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

    /**
     * Notes the THMP crew wrote on this work order's Jobber job. Read-only
     * copies: kept apart from notes() so the PropertyWare reconciliation
     * cannot delete them and the PropertyWare push cannot pick them up.
     */
    public function jobberNotes(): HasMany
    {
        return $this->hasMany(WorkOrderJobberNote::class, 'work_order_id');
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
     * Narrow to the work orders the given vendor is assigned to, as the boards'
     * Vendor chip means it: for the logins the THMP rule applies to, when that
     * vendor is THMP, work orders that also carry a vendor THMP is only paired
     * with for the Jobber job (Jimmie Gendke SFA) are left out — see
     * Vendor::thmpFilterExclusions(). Everyone else gets the plain match. Every
     * server-side vendor filter (boards, Summary popup, export) goes through
     * here so they all agree with the cards on screen.
     */
    public function scopeAssignedToVendor($query, int|string $vendorId)
    {
        $query->whereHas('vendors', fn ($vendors) => $vendors->where('work_order_vendors.vendor_id', $vendorId));

        $hiddenVendorIds = Vendor::thmpFilterExclusions()[(int) $vendorId] ?? [];

        if ($hiddenVendorIds !== []) {
            $query->whereDoesntHave('vendors', fn ($vendors) => $vendors->whereIn('work_order_vendors.vendor_id', $hiddenVendorIds));
        }

        return $query;
    }

    /**
     * Constrain to the HOA violations this feature is actually running: those
     * holding an HOA upload token, whether it came from a notice upload or from
     * adopting a work order raised in PropertyWare.
     *
     * Deliberately NOT matched on the category. PropertyWare carries years of
     * work orders categorized "HOA Violation" that predate this feature, and
     * matching them put 120 dead cards on the board. They are recognised by
     * isHoaViolation() — enough to keep the owner automation off them — but they
     * are not live work, so they stay off the board until someone adopts one.
     */
    public function scopeHoaViolations($query)
    {
        $query->whereHas('tenantUploadTokens', function ($tokens) {
            $tokens->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION);
        });

        return $query;
    }

    /**
     * The heating and cooling work orders, for the HVAC board.
     *
     * Matched with LIKE, never an equality test. PropertyWare's real picklist
     * value is "HVAC " with a trailing space (1,444 work orders carry it); only
     * six carry a clean "HVAC", so where('category', 'HVAC') would find six rows
     * instead of ~3,000. The same trailing space is why
     * WorkOrderCategory::canonicalName() exists.
     *
     * Some work orders carry HVAC as the type ("HVAC Maintenance") and leave the
     * category blank, the same inconsistency isTurnover() documents, so both
     * columns are matched.
     *
     * Water heaters are plumbing rather than heating and cooling, so they are
     * excluded even though "Water heater" contains the %heater% term.
     *
     * The whereNull branches are required: in SQL, NULL NOT LIKE '%x%' evaluates
     * to NULL, so without them a work order with no category would be dropped.
     */
    public function scopeHvac($query)
    {
        return $query
            // An outside customer's HVAC job belongs to the Crystal Creek Air
            // board alone; this scope also feeds the badge counters, so the
            // exclusion lives here rather than in each caller.
            ->notCrystalCreek()
            ->where(function ($q) {
                $q->where('category', 'LIKE', '%hvac%')
                    ->orWhere('type', 'LIKE', '%hvac%')
                    ->orWhere('category', 'LIKE', '%ac filter%')
                    ->orWhere('category', 'LIKE', '%thermostat%')
                    ->orWhere('category', 'LIKE', '%heater%')
                    ->orWhere('category', 'LIKE', '%furnace%')
                    ->orWhere('category', 'LIKE', '%central heating%');
            })
            ->where(function ($q) {
                $q->whereNull('category')
                    ->orWhere('category', 'NOT LIKE', '%water heater%');
            });
    }

    /**
     * Tenant Easy Fix work: the ones the automation matched to a handbook item
     * (easy_fix_key, written once at intake) or that a coordinator put in
     * "Checking for Tenant Easy Fix" by hand. Either signal counts.
     *
     * The status branch is a whereIn on the tiny service_status table rather
     * than a whereHas, so the count behind the sidebar badge stays a single
     * pass over work_orders with no correlated join per row.
     */
    public function scopeTenantEasyFix($query)
    {
        return $query->notCrystalCreek()->where(function ($q) {
            $q->whereNotNull('easy_fix_key')
                ->orWhereIn('service_status_id', ServiceStatus::query()
                    ->select('id')
                    ->where('name', TenantEasyFixService::EASY_FIX_STATUS));
        });
    }

    /**
     * Whether this work order is on the Tenant Easy Fix board, from what is
     * already loaded. The status name needs the service_status relation.
     */
    public function isTenantEasyFix(): bool
    {
        return $this->easy_fix_key !== null
            || $this->service_status?->name === TenantEasyFixService::EASY_FIX_STATUS;
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
            ->when($filters['vendor'] ?? '', fn ($q, $vendorId) => $q->assignedToVendor($vendorId))
            ->when(request()->filled(['start_date', 'end_date']) ?? '', function ($q) {
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

    /**
     * Restrict to the work orders one kanban board shows.
     *
     * These predicates mirror the board methods on WorkOrderController — which
     * build ServiceStatus queries with the work orders nested, a shape that
     * cannot be reused for aggregates. Keep the two in step: BoardSummaryTest
     * asserts this scope and the board endpoint return the same work orders.
     *
     * An unknown board key leaves the query untouched rather than silently
     * returning everything as if it were the main board.
     */
    public function scopeForBoard($query, string $board)
    {
        return match ($board) {
            // NULL NOT LIKE '%x%' evaluates to NULL in SQL, so without the
            // whereNull branches a work order imported with a blank type or
            // category would vanish from every board.
            'main' => $query->where('status', 'Open')
                ->notCrystalCreek()
                ->where(function ($q) {
                    $q->whereNull('category')
                        ->orWhere('category', 'NOT LIKE', '%move out inspection%');
                })
                ->where(function ($q) {
                    $q->whereNull('type')
                        ->orWhere(function ($t) {
                            $t->where('type', 'NOT LIKE', '%Biweekly Lawn Services%')
                                ->where('type', 'NOT LIKE', '%Turnover%');
                        });
                }),

            'inspections' => $query->where('category', 'LIKE', '%move out inspection%')
                ->notCrystalCreek()
                ->where('status', 'Open'),

            'lawn_service' => $query->where(function ($q) {
                $q->where('category', 'LIKE', '%lawn service%')
                    ->orWhere('type', 'LIKE', '%biweekly lawn services%');
            })->notCrystalCreek()->where('status', 'Open'),

            'turnovers' => $query->where('type', 'Turnover')
                ->notCrystalCreek()
                ->where('status', 'Open'),

            'closed' => $query->where(function ($q) {
                $q->where('status', 'Closed')
                    ->orWhere('status', 'Canceled By Tenant');
            })->notCrystalCreek(),

            'waiting_on_payment' => $query->where('status', 'Open')
                ->notCrystalCreek()
                ->whereHas('service_status', fn ($q) => $q->where('name', self::WAITING_ON_PAYMENT_STATUS)),

            'paid' => $query->whereNotNull('total_cost')
                ->notCrystalCreek()
                ->where('total_cost', '>', 0)
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(self::COMPLETED_WINDOW_DAYS)),

            'hoa' => $query->hoaViolations(),

            // HVAC work orders deliberately stay on the main board too, the way
            // HOA violations do, so nobody loses sight of them.
            'hvac' => $query->hvac()->where('status', 'Open'),

            // Same for Tenant Easy Fix: an extra view, not a move.
            'easy_fix' => $query->tenantEasyFix()->where('status', 'Open'),

            // Crystal Creek Air IS a move: outside customers' jobs show here
            // and nowhere else (every arm above carries notCrystalCreek()).
            'crystal_creek' => $query->crystalCreek()->where('status', 'Open'),

            default => $query,
        };
    }

    /**
     * The search/vendor/category/date-range filter block every board shares.
     *
     * Deliberately separate from scopeFilter(), which matches work_order_no and
     * location with a LIKE. The boards match work_order_no exactly, so the
     * summary must too or its figures would not match the cards on screen.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeBoardFilters($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('work_order_no', $search);
            })
            ->when($filters['vendor'] ?? null, fn ($q, $vendorId) => $q->assignedToVendor($vendorId))
            ->when($filters['category'] ?? null, function ($q, $category) {
                $q->where('category', $category);
            })
            ->when(
                filled($filters['start_date'] ?? null) && filled($filters['end_date'] ?? null),
                function ($q) use ($filters) {
                    $q->whereBetween('created_date', [
                        Carbon::parse($filters['start_date'])->startOfDay(),
                        Carbon::parse($filters['end_date'])->endOfDay(),
                    ]);
                }
            )
            ->emergencyFilter($filters['emergency'] ?? null);
    }

    public function scopeFiltered($query)
    {
        $query->when(request('search'), function ($q, $search) {
            $q->where('work_order_no', $search);
        })
            ->when(request('vendor'), fn ($q, $vendorId) => $q->assignedToVendor($vendorId))
            ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                $date = request()->only(['start_date', 'end_date']);
                $start = Carbon::parse($date['start_date'])->startOfDay();
                $end = Carbon::parse($date['end_date'])->endOfDay();
                $q->whereBetween('created_date', [$start, $end]);
            })
            ->where('status', 'Open');
    }
}
