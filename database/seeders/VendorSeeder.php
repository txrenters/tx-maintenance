<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDOStatement;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s');

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
        $csvPath = database_path('seeders/data/vendor_types.csv');

        if (! File::exists($csvPath)) {
            Log::error('CSV file not found: '.$csvPath);

            return;
        }

        $handle = fopen($csvPath, 'r');
        fgets($handle); // Skip the header row

        $chunkSize = 500;
        $chunks = [];

        try {
            $pdo = DB::connection()->getPdo();
            $stmt = $this->prepareChunkedStatementVendorTypes($chunkSize);

            while (($row = fgetcsv($handle)) !== false) {
                // Fix: Push values as an indexed array
                $chunks[] = [
                    $row[1] ?? null,
                    $now,
                    $now,
                ];

                // Execute query when chunk reaches batch size
                if (count($chunks) == $chunkSize) {
                    $stmt->execute(array_merge(...$chunks));
                    $chunks = [];
                }
            }

            // Execute remaining records
            if (! empty($chunks)) {
                $remainingRows = count($chunks);
                $stmt = $this->prepareChunkedStatementVendorTypes($remainingRows);
                $stmt->execute(array_merge(...$chunks));
            }
        } finally {
            fclose($handle);
        }
    }

    private function seedVendors($now): void
    {
        $csvPath = database_path('seeders/data/vendors.csv');

        if (! File::exists($csvPath)) {
            Log::error('CSV file not found: '.$csvPath);

            return;
        }

        $handle = fopen($csvPath, 'r');

        fgets($handle); // Skip the header row
        $chunkSize = 500;
        $chunks = [];

        try {
            $pdo = DB::connection()->getPdo();

            $stmt = $this->prepareChunkedStatementVendors($chunkSize);

            $chunkedPropertywareIds = [];

            while (($row = fgetcsv($handle)) !== false) {

                // Preload existing vendor property IDs and vendor types
                $existingVendors = DB::table('vendors')->pluck('propertyware_id')->all();
                $vendorTypes = DB::table('vendor_types')->pluck('name', 'id')->all();

                $vendor_propertyware_id = $row[5] ?? null;

                if (empty($vendor_propertyware_id) || $vendor_propertyware_id == 'NULL' || in_array($vendor_propertyware_id, $existingVendors) || in_array($vendor_propertyware_id, $chunkedPropertywareIds)) {
                    continue; // Skip if vendor already exists
                }

                $email = $row[16] ?? null;

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Create a fallback unique email using propertyware ID
                    $vendorEmail = $vendor_propertyware_id.'@texasrenter.com';
                } else {
                    $vendorEmail = $email;
                }

                $address = trim(implode(' ', array_filter([
                    $row[18] ?? null,  // address
                    $row[19] ?? null,  // city
                    $row[20] ?? null, // comments (not part of address)
                    $row[21] ?? null, // zip
                ])));

                $address = preg_replace('/[^\x20-\x7E]/u', '', $address);
                $address = str_replace("\xC2\xA0", ' ', $address); // Replace non-breaking spaces

                $phone = $row[21] ?? null;
                $phone = preg_replace('/[^\x20-\x7E]/u', '', $phone);
                $phone = str_replace("\xC2\xA0", ' ', $phone); // Replace non-breaking spaces

                $usersData = [
                    'email' => $vendorEmail,
                    'name' => $row[14],
                    'phone' => $phone,  // homePhone
                    'company' => $row[15] ?? null, // company
                    'address' => $address,
                    'password' => bcrypt($vendorEmail),
                ];

                // Creating or updating user in bulk
                $user_id = $this->createOrUpdateUser($usersData, 'vendor');

                $chunks[] = [
                    $vendor_propertyware_id,
                    $row[14] ?? $row[7],
                    $vendorEmail,
                    $row[7] ?? null,
                    $vendorTypes[$row[3]] ?? null,
                    $row[9] ?? 1,
                    $user_id,
                    $now,
                    $now,
                ];

                $chunkedPropertywareIds[] = $vendor_propertyware_id;

                // Execute query when chunk reaches batch size
                if (count($chunks) == $chunkSize) {
                    $stmt->execute(array_merge(...$chunks));
                    $chunks = [];
                    $chunkedPropertywareIds = [];

                }
            }
            if (! empty($chunks)) {
                $remainingRows = count($chunks);
                $stmt = $this->prepareChunkedStatementVendors($remainingRows);
                $stmt->execute(array_merge(...$chunks));
            }
        } finally {
            fclose($handle);
        }
    }

    private function createOrUpdateUser(array $data, string $role): int
    {
        static $existingUsers = null; // Cache user list in memory during execution

        if ($existingUsers == null) {
            $existingUsers = User::pluck('id', 'email')->toArray(); // Fetch once
        }

        // Check if the user already exists
        if (isset($existingUsers[$data['email']])) {
            $userId = $existingUsers[$data['email']];
        } else {
            // Insert and get the ID of the newly created user
            $userId = DB::table('users')->insertGetId($data);

            // Update the local cache with the new user ID
            $existingUsers[$data['email']] = $userId;

            // Assign the role to the new user
            $user = User::find($userId);
            $user->assignRole($role); // Assign the role only to new users
        }

        return $userId; // Return the user ID
    }

    private function prepareChunkedStatementVendors($chunkSize): PDOStatement
    {
        $rowPlaceholders = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $placeholders = implode(',', array_fill(0, $chunkSize, $rowPlaceholders));

        return DB::connection()->getPdo()->prepare('
        INSERT INTO vendors (propertyware_id, name, email, name_on_check, vendor_type, is_active, user_id, created_at, updated_at)
        VALUES '.$placeholders);
    }

    private function prepareChunkedStatementVendorTypes($chunkSize): PDOStatement
    {
        $rowPlaceholders = '(?, ?, ?)';
        $placeholders = implode(',', array_fill(0, $chunkSize, $rowPlaceholders));

        $query = DB::connection()->getPdo()->prepare('
        INSERT INTO vendor_types (name, created_at, updated_at) 
        VALUES '.$placeholders);

        return $query;

    }
}
