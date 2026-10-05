<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\ActivityBoards;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'hvac_board_seen_at' => 'datetime',
            'easy_fix_board_seen_at' => 'datetime',
        ];
    }

    /**
     * The in-office roles. Everyone else who can log in — vendors, tenants and
     * owners — is an outside party, as is a user carrying no role at all.
     */
    public const STAFF_ROLES = ['admin', 'woc', 'accounting'];

    /**
     * True when this user works for the office rather than being a vendor,
     * tenant or owner. Used to gate the Jobber job pages and every action on
     * them, so the whole feature moves together.
     */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }

    /**
     * Whether this user gets the "new activity" counters on the HVAC board and
     * the matching number beside HVAC in the sidebar: every staff login.
     */
    public function seesHvacBoardActivity(): bool
    {
        return ActivityBoards::sees($this, ActivityBoards::HVAC);
    }

    /**
     * The same for the Tenant Easy Fix board.
     */
    public function seesEasyFixBoardActivity(): bool
    {
        return ActivityBoards::sees($this, ActivityBoards::EASY_FIX);
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class, 'user_id');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenants::class);
    }

    public function tenant(): HasOne
    {
        return $this->hasOne(Tenants::class, 'user_id');
    }

    public function owners(): HasMany
    {
        return $this->hasMany(Owner::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(Owner::class, 'user_id');
    }

    public function work_orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'user_id');
    }

    public function wocNumber(): HasOne
    {
        return $this->hasOne(WOCNumbers::class);
    }

    public function wocNumbers(): HasMany
    {
        return $this->hasMany(WOCNumbers::class);
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'name',
                    'email',
                    'phone',
                    'address',
                    'company',
                    'website',
                ], 'LIKE', "%{$search}%");
        }
    }

    public function getPhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) === 10) { // US number without country code
            $cleaned = '+1'.$cleaned;
        } elseif (strlen($cleaned) > 10 && strpos($cleaned, '1') === 0) {
            $cleaned = '+'.$cleaned;
        }

        return $cleaned;
    }

    public function setPhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) === 10) { // US number without country code
            $cleaned = '+1'.$cleaned;
        } elseif (strlen($cleaned) > 10 && strpos($cleaned, '1') === 0) {
            $cleaned = '+'.$cleaned;
        }

        $this->attributes['phone'] = $cleaned;
    }
}
