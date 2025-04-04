<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

class VendorSeeder_copy extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Disable query logging and events for performance
        DB::disableQueryLog();
        DB::connection()->unsetEventDispatcher();
        User::flushEventListeners();

        // Seed Vendor Types
        $this->seedVendorTypes($now);

        // Seed Vendors
        $this->seedVendors($now);
    }

    private function seedVendorTypes($now): void
    {
        $csvPath = public_path('vendor_types.csv');

        if (!File::exists($csvPath)) {
            Log::error('CSV file not found: ' . $csvPath);
            return;
        }

        $vendorTypes = LazyCollection::make(function () use ($csvPath, $now) {
            $file = fopen($csvPath, 'r');
            $header = fgetcsv($file);
            
            if (!$header) {
                Log::error('Invalid CSV format in vendor_types.csv.');
                fclose($file);
                return;
            }

            while (($row = fgetcsv($file)) !== false) {
                if (count($row) !== count($header)) {
                    Log::warning('Skipping malformed row in vendor_types.csv: ' . json_encode($row));
                    continue;
                }

                $data = array_combine($header, $row);
                yield [
                    'name' => $data['title'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            fclose($file);
        });

        // Insert in batches of 500
        $vendorTypes->chunk(500)->each(function ($chunk) {
            DB::table('vendor_types')->insert($chunk->toArray());
        });
    }

    private function seedVendors($now): void
    {
        $csvPath = public_path('vendors.csv');

        if (!File::exists($csvPath)) {
            Log::error('CSV file not found: ' . $csvPath);
            return;
        }

        // Preload existing vendor property IDs and vendor types
        $existingVendors = DB::table('vendors')->pluck('propertyware_id')->all();
        $vendorTypes = DB::table('vendor_types')->pluck('name', 'id')->all();

        // Process vendors in batches
        LazyCollection::make(function () use ($csvPath, $now, $existingVendors, $vendorTypes) {
            $file = fopen($csvPath, 'r');
            $header = fgetcsv($file);
            
            if (!$header) {
                Log::error('Invalid CSV format in vendors.csv.');
                fclose($file);
                return;
            }

            while (($row = fgetcsv($file)) !== false) {
                if (count($row) !== count($header)) {
                    Log::warning('Skipping malformed row in vendors.csv: ' . json_encode($row));
                    continue;
                }

                $vendor = array_combine($header, $row);
                $vendorId = !empty($vendor['_id']) ? trim($vendor['_id']) : null;

                if (empty($vendorId) || in_array($vendorId, $existingVendors) || $vendorId == 'NULL') {
                    continue;
                }

                yield $this->prepareVendorData($vendor, $vendorTypes, $now);
            }
            fclose($file);
        })
        ->chunk(500)
        ->each(function ($chunk) {
            // Bulk insert vendors
            DB::table('vendors')->insert($chunk->pluck('vendor')->toArray());
            
            // Bulk create users
            User::insert($chunk->pluck('user')->toArray());
            
            // Assign roles (if needed)
            $this->assignRolesInBulk($chunk->pluck('user.email')->toArray(), 'vendor');
        });
    }

    private function prepareVendorData(array $vendor, array $vendorTypes, $now): array
    {
        $vendorId = trim($vendor['_id']);
        $vendorEmail = $vendor['email'] ?? ($vendorId . '@txrenters.com');
        
        $address = trim(implode(' ', array_filter([
            $vendor['address'] ?? null,
            $vendor['city'] ?? null,
            $vendor['state'] ?? null,
            $vendor['zipCode'] ?? null,
        ])));
        $address = preg_replace('/[^\x20-\x7E]/u', '', $address);

        $vendorTypeName = $vendorTypes[$vendor['vendorTypeId'] ?? ''] ?? 'Unknown';

        return [
            'vendor' => [
                'propertyware_id' => $vendorId,
                'name' => $vendor['name'] ?? $vendor['nameOnCheck'] ?? 'Unknown Vendor',
                'email' => $vendorEmail,
                'name_on_check' => $vendor['nameOnCheck'] ?? null,
                'vendor_type' => $vendorTypeName,
                'twilio_number' => $vendor['twilioNumberId'] ?? '',
                'is_active' => $vendor['isActive'] ?? 1,
                'user_id' => null, // Will be updated after user creation
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'user' => [
                'email' => $vendorEmail,
                'name' => $vendor['name'] ?? $vendor['nameOnCheck'] ?? 'Unknown Vendor',
                'phone' => $vendor['phone'] ?? null,
                'company' => $vendor['companyName'] ?? null,
                'address' => $address,
                'website' => $vendor['website'] ?? null,
                'password' => bcrypt($vendorEmail),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        ];
    }

    private function assignRolesInBulk(array $emails, string $role): void
    {
        $users = User::whereIn('email', $emails)->get();
        $users->each->assignRole($role);
    }
}