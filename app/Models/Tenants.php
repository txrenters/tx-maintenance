<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenants extends Model
{
    /** @use HasFactory<\Database\Factories\TenantsFactory> */
    use HasFactory;

    protected $table = 'tenants';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $casts = [
        'home_phone' => 'string',
        'mobile_phone' => 'string',
    ];

    public function work_orders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_tenants');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'first_name',
                    'last_name',
                    'email',
                    'home_phone',
                    'mobile_phone',
                    'company',
                ], 'LIKE', "%{$search}%");
        }
    }

    // Accessor - Format when retrieving
    public function getHomePhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['home_phone'] = $cleaned;
    }

    // Accessor - Format when retrieving
    public function getMobilePhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['mobile_phone'] = $cleaned;
    }

    public function setHomePhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['home_phone'] = $cleaned;

    }

    public function setMobilePhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['mobile_phone'] = $cleaned;

    }
}
