<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Vendor extends Model
{
    use Notifiable;

    protected $table = 'vendors';

    /**
     * The exact name of the in-house vendor "Texas Home Maintenance Pros"
     * (THMP). Matched by name so it works regardless of the record's id in any
     * environment, mirroring how the Jobber integration detects THMP jobs.
     */
    public const THMP_NAME = 'Texas Home Maintenance Pros';

    /**
     * THMP's PropertyWare vendor id. The vendors table names each row after
     * PropertyWare's COMPANY name (import:all-vendors), which anyone can edit
     * in PropertyWare, so a rule that must survive a rename keys on this id
     * alongside THMP_NAME. Used by the THMP filter only.
     */
    public const THMP_PROPERTYWARE_ID = '246120584';

    /**
     * Our own repair brand "Crystal Creek Air, LLC", the same crew as THMP,
     * as PropertyWare names the vendor. A Texas Renters work order assigned
     * to this vendor belongs on the Crystal Creek Air page. Matched by name
     * prefix, case-insensitively, so "Crystal Creek Air" with or without the
     * ", LLC" (or a trailing space from PropertyWare) still counts.
     */
    public const CRYSTAL_CREEK_NAME = 'Crystal Creek Air, LLC';

    public const CRYSTAL_CREEK_NAME_PREFIX = 'crystal creek air';

    protected $fillable = [
        'propertyware_id', 'name', 'email', 'name_on_check', 'vendor_type', 'twilio_number', 'is_active', 'user_id', 'zones', 'portal_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'zones' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when this record is the "OWNER VENDOR" placeholder, meaning the
     * property owner handles the repair themselves. No automated vendor or
     * owner texts should be sent for assignments to it. Matched by name so it
     * works regardless of the record's id in any environment.
     */
    public function isOwnerPlaceholder(): bool
    {
        return Str::lower(trim((string) $this->name)) === 'owner vendor';
    }

    /**
     * The e-mail that keys a vendor's portal user when the vendor is imported
     * from PropertyWare. PropertyWare returns "" (not null) for a vendor with
     * no address on file, and `??` only guards null, so every e-mail-less
     * vendor used to land on ONE shared user row (email "") whose phone was
     * whatever record had been written last: "OWNER VENDOR" showed a real
     * vendor's number that way (WO#44092). A blank address now gets the same
     * per-vendor placeholder a missing one always got, so each vendor owns its
     * user, and with it its phone number.
     */
    public static function userEmailFor(?string $email, int|string $propertywareId): string
    {
        return filled($email) ? $email : $propertywareId.'@texasrenter.com';
    }

    /**
     * True when this is the in-house vendor "Texas Home Maintenance Pros".
     * THMP does not reach out to the tenant to schedule the way a third-party
     * vendor does, so tenant-facing automations (assignment notice, the daily
     * "has the vendor reached out?" follow-up) skip it — THMP staff message the
     * tenant manually instead.
     */
    public function isThmp(): bool
    {
        return Str::lower(trim((string) $this->name)) === Str::lower(self::THMP_NAME);
    }

    /**
     * True when THMP is among the vendors assigned to the given work order.
     * Matches the same trimmed, case-insensitive rule as isThmp() so a vendor
     * record with stray whitespace or different casing is still detected.
     */
    /** Whether this vendor is the Crystal Creek Air brand (see CRYSTAL_CREEK_NAME). */
    public function isCrystalCreek(): bool
    {
        return Str::startsWith(Str::lower(trim((string) $this->name)), self::CRYSTAL_CREEK_NAME_PREFIX);
    }

    /**
     * Narrow a vendors query to the Crystal Creek Air vendor row(s), by the
     * same name rule as isCrystalCreek().
     */
    public function scopeCrystalCreek($query)
    {
        return $query->whereRaw('LOWER(TRIM(vendors.name)) LIKE ?', [self::CRYSTAL_CREEK_NAME_PREFIX.'%']);
    }

    public static function isThmpAssignedToWorkOrder(int $workOrderId): bool
    {
        return DB::table('work_order_vendors')
            ->join('vendors', 'vendors.id', '=', 'work_order_vendors.vendor_id')
            ->where('work_order_vendors.work_order_id', $workOrderId)
            ->whereRaw('LOWER(TRIM(vendors.name)) = ?', [Str::lower(trim(self::THMP_NAME))])
            ->exists();
    }

    /**
     * Whether the THMP filter rule below is switched on for this login.
     *
     * Deliberately per login, never global: WOC staff must still find SFA's
     * work orders under the THMP filter to process them and message the
     * tenant, so only the emails in services.jobber.thmp_filter_users (THMP's
     * own login, John Carlo, by default) get the trimmed view. Case-insensitive.
     */
    public static function thmpFilterAppliesTo(User $user): bool
    {
        $email = Str::lower(trim((string) $user->email));

        if ($email === '') {
            return false;
        }

        return collect(explode(',', (string) config('services.jobber.thmp_filter_users', '')))
            ->map(fn (string $listed): string => Str::lower(trim($listed)))
            ->contains($email);
    }

    /**
     * Vendor ids whose work orders a board's Vendor filter must hide when the
     * selected vendor is THMP, keyed by THMP vendor id — for the logins the
     * rule applies to (thmpFilterAppliesTo); empty for everyone else.
     *
     * Staff tag THMP onto Jimmie Gendke SFA's work orders only so this app
     * creates the Jobber job (PropertyWareService::changeWorkOrderVendors);
     * the work is SFA's, so THMP's queue must not list it.
     *
     * The hidden vendors are keyed on PropertyWare vendor id
     * (services.jobber.thmp_filter_hidden_vendor_ids), not on name: the
     * vendors table names a row after PropertyWare's COMPANY name, so in
     * production SFA's row reads "Jimmie" (it read "THMP" until 2026-09-12),
     * and a name-only rule hid nothing (WO#44085, #44083). Exact trimmed,
     * case-insensitive names (services.jobber.thmp_filter_hidden_vendors) are
     * honoured as well — never LIKE, so "SFA - Michael" is not caught by
     * "Jimmie Gendke SFA". Every row that is THMP, by THMP_PROPERTYWARE_ID or
     * by name, is a key because some environments carry more than one such
     * row while the filter chip sends a single id. Empty when both settings
     * are blank or no matching rows exist. Memoized for the request; defaults
     * to the signed-in user, so a queued or console caller with nobody signed
     * in gets [].
     *
     * @return array<int, list<int>>
     */
    public static function thmpFilterExclusions(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! self::thmpFilterAppliesTo($user)) {
            return [];
        }

        return once(function (): array {
            $hiddenPropertywareIds = self::thmpFilterList('services.jobber.thmp_filter_hidden_vendor_ids');
            $hiddenNames = self::thmpFilterList('services.jobber.thmp_filter_hidden_vendors')
                ->map(fn (string $name): string => Str::lower($name));

            if ($hiddenPropertywareIds->isEmpty() && $hiddenNames->isEmpty()) {
                return [];
            }

            $thmpName = Str::lower(trim(self::THMP_NAME));
            $thmpPropertywareId = self::THMP_PROPERTYWARE_ID;

            $rows = DB::table('vendors')
                ->select('id', 'propertyware_id', DB::raw('LOWER(TRIM(name)) as normalized_name'))
                ->where(function ($query) use ($hiddenPropertywareIds, $hiddenNames, $thmpName, $thmpPropertywareId): void {
                    $query->whereIn(DB::raw('LOWER(TRIM(name))'), $hiddenNames->merge([$thmpName])->all())
                        ->orWhereIn('propertyware_id', $hiddenPropertywareIds->merge([$thmpPropertywareId])->all());
                })
                ->get();

            $isThmp = fn (object $row): bool => $row->normalized_name === $thmpName
                || trim((string) $row->propertyware_id) === $thmpPropertywareId;

            $thmpIds = $rows->filter($isThmp)->pluck('id')->map(fn ($id): int => (int) $id)->values();
            $hiddenIds = $rows->reject($isThmp)->pluck('id')->map(fn ($id): int => (int) $id)->values();

            if ($thmpIds->isEmpty() || $hiddenIds->isEmpty()) {
                return [];
            }

            return $thmpIds->mapWithKeys(fn (int $thmpId): array => [$thmpId => $hiddenIds->all()])->all();
        });
    }

    /**
     * A comma-separated config value as a list of trimmed, non-blank,
     * distinct entries.
     *
     * @return Collection<int, string>
     */
    private static function thmpFilterList(string $configKey): Collection
    {
        return collect(explode(',', (string) config($configKey, '')))
            ->map(fn (string $entry): string => trim($entry))
            ->filter()
            ->unique()
            ->values();
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_vendors', 'vendor_id', 'work_order_id')
            ->withPivot('access_token', 'cost_estimate', 'time_estimate', 'scheduled_end_date')
            ->withTimestamps();
    }

    /**
     * Jobber jobs (non-TexasRenters client properties) assigned to this vendor.
     */
    public function jobberJobs(): BelongsToMany
    {
        return $this->belongsToMany(Jobber::class, 'jobber_job_vendors', 'vendor_id', 'jobber_job_id')
            ->using(JobberJobVendor::class)
            ->withPivot('access_token', 'cost_estimate', 'scheduled_end_date', 'information_sent_at')
            ->withTimestamps();
    }

    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Return this vendor's stable no-login dashboard token, creating it if absent.
     */
    public function ensurePortalToken(): string
    {
        if (! $this->portal_token) {
            $this->portal_token = static::generateUniquePortalToken();
            $this->save();
        }

        return $this->portal_token;
    }

    /**
     * Generate a random dashboard token that is guaranteed unique across vendors.
     */
    public static function generateUniquePortalToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('portal_token', $token)->exists());

        return $token;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function vendor_notes(): HasMany
    {
        return $this->hasMany(WorkOrderNotes::class);
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'name',
                    'email',
                    'name_on_check',
                    'vendor_type',
                ], 'LIKE', "%{$search}%");
        }

        if (! empty($filter['status'])) {
            $status = $filter['status'];

            if ($status != 'All') {
                $query->where(
                    'is_active', $status == 'Active' ? true : false);
            }
        }
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }
}
