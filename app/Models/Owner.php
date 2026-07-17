<?php

namespace App\Models;

use Database\Factories\OwnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    /** @use HasFactory<OwnerFactory> */
    use HasFactory;

    protected $table = 'owners';

    protected $guarded = [];

    protected $casts = [
        'phone' => 'string',
        'mobile' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work_orders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_owners');
    }

    public function managementWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'property_manager_id');
    }

    public function emailNotifications(): HasMany
    {
        return $this->hasMany(OwnerEmailNotification::class);
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
                    'mobile',
                    'company',
                    'name_on_check',
                ], 'LIKE', "%{$search}%");
        }
    }

    // Accessor - Format when retrieving
    public function getPhoneAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value ?? ''); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['phone'] = $cleaned;
    }

    public function getMobileAttribute($value)
    {
        $cleaned = preg_replace('/\D+/', '', $value ?? ''); // Remove non-numeric characters
        if (strlen($cleaned) == 10) { // If it's a US number without country code
            $cleaned = '+1'.$cleaned;
        }

        return $this->attributes['mobile'] = $cleaned;
    }

    // Mutator - Format when saving
    // public function setPhoneAttribute($value)
    // {
    //     $this->attributes['phone'] = preg_replace('/[^0-9]/', '', $value ?? '');

    //     $cleaned = preg_replace('/\D+/', '', $value ?? ''); // Remove non-numeric characters
    //     if (strlen($cleaned) == 10) { // If it's a US number without country code
    //         $cleaned = '+1' . $cleaned;
    //     }
    //     return $this->attributes['phone'] = $cleaned;
    // }
    // public function setMobileAttribute($value)
    // {

    //     $cleaned = preg_replace('/\D+/', '', $value ?? ''); // Remove non-numeric characters
    //     if (strlen($cleaned) == 10) { // If it's a US number without country code
    //         $cleaned = '+1' . $cleaned;
    //     }
    //     return $this->attributes['mobile'] = $cleaned;

    // }
}
