<?php

namespace Database\Seeders;

use App\Models\FallbackVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FallbackVendorsSeeder extends Seeder
{
    public function run(): void
    {
        $fallbacks = [
            [
                'name' => 'Texas Home Maintenance Pros',
                'vendor_type' => 'Handyman',
                'priority' => 10,
                'notes' => 'Use for simple door lock replacement or repair when the front or exit door cannot be locked or unlocked.',
                'contacts' => [
                    ['name' => 'Romero', 'phone' => '(305) 303-3420'],
                    ['name' => 'Carlos', 'phone' => '(786) 501-0690'],
                ],
                'issue_types' => ['Lock Repair'],
                'keywords' => ['front door', 'exit door', 'door lock', 'lock replacement', 'lock repair', 'deadbolt', 'cannot lock', 'cannot unlock'],
            ],
            [
                'name' => 'Bi Polar Air Conditioning & Heating LLC',
                'vendor_type' => 'HVAC',
                'priority' => 10,
                'notes' => 'Use for air conditioning and heater issues only.',
                'contacts' => [
                    ['phone' => '(832) 909-0022'],
                ],
                'issue_types' => ['HVAC'],
                'keywords' => ['air conditioning', 'heater', 'ac', 'a/c', 'cooling', 'heating', 'furnace'],
            ],
            [
                'name' => 'Express Key Svc, LLC',
                'vendor_type' => 'Locksmith',
                'priority' => 10,
                'notes' => 'Use when the tenant locks themselves out of the home or garage. Tenant is responsible for the cost.',
                'contacts' => [
                    ['phone' => '(512) 800-3464'],
                ],
                'issue_types' => ['Lockout'],
                'keywords' => ['lockout', 'locked out', 'garage lockout', 'tenant locked out'],
            ],
            [
                'name' => 'Justin Time Garage Doors',
                'vendor_type' => 'Garage Door',
                'priority' => 10,
                'notes' => 'Use for garage door issues.',
                'contacts' => [
                    ['phone' => '(832) 800-8687'],
                ],
                'issue_types' => ['Garage Door'],
                'keywords' => ['garage door', 'garage opener', 'garage'],
            ],
            [
                'name' => 'SDM Home Services LLC',
                'vendor_type' => 'Plumbing',
                'priority' => 20,
                'notes' => '24/7 plumber. Use for water leaks, busted pipes, no water, no hot water, and other plumbing issues. Also handles HVAC issues.',
                'contacts' => [
                    ['phone' => '(281) 844-8563'],
                ],
                'issue_types' => ['Plumbing', 'HVAC'],
                'keywords' => ['water leak', 'busted pipe', 'no water', 'no hot water', 'plumbing', 'hvac'],
            ],
            [
                'name' => 'Professionals Same Day Repair',
                'vendor_type' => 'Plumbing',
                'priority' => 30,
                'notes' => '24/7 plumber. Use for water leaks, busted pipes, no water, no hot water, and other plumbing issues. Also handles HVAC issues.',
                'contacts' => [
                    ['phone' => '(832) 708-4891'],
                ],
                'issue_types' => ['Plumbing', 'HVAC'],
                'keywords' => ['water leak', 'busted pipe', 'no water', 'no hot water', 'plumbing', 'hvac'],
            ],
            [
                'name' => 'RA Property Solutions LLC',
                'vendor_type' => 'General Maintenance',
                'priority' => 50,
                'notes' => 'General fallback vendor when no prior vendor history or specialized fallback mapping applies.',
                'contacts' => [
                    ['phone' => '(346) 760-9532', 'email' => 'main@rapropertysolutions.net'],
                ],
                'issue_types' => ['General Maintenance'],
                'keywords' => ['general maintenance', 'repair', 'maintenance'],
            ],
        ];

        foreach ($fallbacks as $entry) {
            $vendor = Vendor::whereRaw('LOWER(name) = ?', [Str::lower($entry['name'])])->first();

            if ($vendor === null) {
                $vendor = $this->createStubVendor($entry);
            } elseif (blank($vendor->vendor_type) && filled($entry['vendor_type'])) {
                $vendor->update(['vendor_type' => $entry['vendor_type']]);
            }

            FallbackVendor::updateOrCreate(
                ['vendor_id' => $vendor->id],
                [
                    'contacts' => $entry['contacts'],
                    'notes' => $entry['notes'],
                    'issue_types' => $entry['issue_types'],
                    'keywords' => $entry['keywords'],
                    'priority' => $entry['priority'],
                    'is_active' => true,
                ],
            );

            $this->command?->info("Seeded fallback for: {$vendor->name}");
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function createStubVendor(array $entry): Vendor
    {
        $slug = Str::slug($entry['name']);
        $email = "{$slug}@fallback.local";

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $entry['name'],
                'password' => bcrypt(Str::random(16)),
            ],
        );

        if (! $user->hasRole('vendor')) {
            $user->assignRole('vendor');
        }

        return Vendor::create([
            'propertyware_id' => 'fallback-'.$slug,
            'name' => $entry['name'],
            'name_on_check' => $entry['name'],
            'email' => $email,
            'vendor_type' => $entry['vendor_type'],
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }
}
