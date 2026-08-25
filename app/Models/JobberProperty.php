<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobberProperty extends Model
{
    protected $table = 'jobber_properties';

    protected $guarded = [];

    protected $appends = ['full_address'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * The coordinates Jobber holds for a property, from the payload's
     * address.coordinates. Both null when Jobber has none for the address.
     *
     * @param  array<string, mixed>  $propertyData
     * @return array{latitude: float|null, longitude: float|null}
     */
    public static function coordinatesFromApi(array $propertyData): array
    {
        $latitude = data_get($propertyData, 'address.coordinates.latitude');
        $longitude = data_get($propertyData, 'address.coordinates.longitude');

        return is_numeric($latitude) && is_numeric($longitude)
            ? ['latitude' => (float) $latitude, 'longitude' => (float) $longitude]
            : ['latitude' => null, 'longitude' => null];
    }

    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->street,
            $this->city,
            $this->province,
            $this->postal_code,
            $this->country,
        ])->filter()->implode(', ');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'street',
                    'city',
                    'province',
                    'postal_code',
                    'country',
                ], 'LIKE', "%{$search}%");
        }
    }
}
