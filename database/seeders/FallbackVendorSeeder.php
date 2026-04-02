<?php

namespace Database\Seeders;

use App\Models\FallbackVendor;
use Illuminate\Database\Seeder;

class FallbackVendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'name' => 'Texas Home Maintenance Pros',
                'contacts' => [
                    ['name' => 'Romero', 'phone' => '(305) 303-3420'],
                    ['name' => 'Carlos', 'phone' => '(786) 501-0690'],
                ],
                'notes' => 'Use for simple door lock replacement or repair when the front or exit door cannot be locked or unlocked.',
                'issue_types' => ['Lock Repair'],
                'keywords' => ['front door', 'exit door', 'door lock', 'lock replacement', 'lock repair', 'deadbolt', 'cannot lock', 'cannot unlock'],
                'priority' => 10,
            ],
            [
                'name' => 'SDM Home Services LLC',
                'contacts' => [
                    ['phone' => '(281) 844-8563'],
                ],
                'notes' => '24/7 plumber. Use for water leaks, busted pipes, no water, no hot water, and other plumbing issues. Also handles HVAC issues.',
                'issue_types' => ['Plumbing', 'HVAC'],
                'keywords' => ['water leak', 'busted pipe', 'no water', 'no hot water', 'plumbing', 'hvac'],
                'priority' => 20,
            ],
            [
                'name' => 'Professional Same day Repair',
                'contacts' => [
                    ['phone' => '(832) 708-4891'],
                ],
                'notes' => '24/7 plumber. Use for water leaks, busted pipes, no water, no hot water, and other plumbing issues. Also handles HVAC issues.',
                'issue_types' => ['Plumbing', 'HVAC'],
                'keywords' => ['water leak', 'busted pipe', 'no water', 'no hot water', 'plumbing', 'hvac'],
                'priority' => 30,
            ],
            [
                'name' => 'Bi-Polar AC',
                'contacts' => [
                    ['phone' => '(832) 909-0022'],
                ],
                'notes' => 'Use for air conditioning and heater issues only.',
                'issue_types' => ['HVAC'],
                'keywords' => ['air conditioning', 'heater', 'ac', 'a/c', 'cooling', 'heating', 'furnace'],
                'priority' => 10,
            ],
            [
                'name' => 'Express Key',
                'contacts' => [
                    ['phone' => '(512) 800-3464'],
                ],
                'notes' => 'Use when the tenant locks themselves out of the home or garage. Tenant is responsible for the cost.',
                'issue_types' => ['Lockout'],
                'keywords' => ['lockout', 'locked out', 'garage lockout', 'tenant locked out'],
                'priority' => 10,
            ],
            [
                'name' => 'Justin Time Garage Doors',
                'contacts' => [
                    ['phone' => '(832) 800-8687'],
                ],
                'notes' => 'Use for garage door issues.',
                'issue_types' => ['Garage Door'],
                'keywords' => ['garage door', 'garage opener', 'garage'],
                'priority' => 10,
            ],
            [
                'name' => 'RA Solutions',
                'contacts' => [
                    ['email' => 'main@rapropertysolutions.net', 'phone' => '(346) 760-9532'],
                ],
                'notes' => 'General fallback vendor when no prior vendor history or specialized fallback mapping applies.',
                'issue_types' => ['General Maintenance'],
                'keywords' => ['general maintenance', 'repair', 'maintenance'],
                'priority' => 50,
            ],
        ];

        foreach ($vendors as $vendor) {
            FallbackVendor::firstOrCreate(['name' => $vendor['name']], $vendor);
        }
    }
}
