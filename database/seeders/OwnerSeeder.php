<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        
        // Disable query logging and events for performance
        DB::disableQueryLog();
        DB::connection()->unsetEventDispatcher();
        User::flushEventListeners();
        
        // Read CSV file
        $csvFile = public_path('owners.csv'); 

        if (!File::exists($csvFile)) {
            Log::error('CSV file not found: ' . $csvFile);
            return;
        }

        $handle = fopen($csvFile, 'r');
        fgetcsv($handle); // Skip header row

        $chunkSize = 500;
        $chunks = [];

        try {
            $pdo = DB::connection()->getPdo();
            $stmt = $this->prepareChunkedStatementOwners($chunkSize);

            // Fetch existing propertyware IDs
            $existingOwners = DB::table('owners')->pluck('propertyware_id')->toArray();
            $chunkedPropertywareIds = [];
            while (($owner = fgetcsv($handle)) !== false) {
                $owner_propertyware_id = $owner[3];

                if (empty($owner_propertyware_id) || in_array($owner_propertyware_id, $existingOwners) || in_array($owner_propertyware_id, $chunkedPropertywareIds)) {
                    continue; // Skip empty or duplicate propertyware_id
                }

                // Prepare user data
                $ownerEmail = $owner[12] ?? $owner_propertyware_id . "@texasrenter.com";
                $address = trim(implode(' ', array_filter([ 
                    $owner[4] ?? null, 
                    $owner[5] ?? null, 
                    $owner[7] ?? null, 
                    $owner[22] ?? null, 
                    $owner[10] ?? null, 
                    $owner[25] ?? null,
                ])));

                $address = preg_replace('/[^\x20-\x7E]/u', '', $address);
                $address = str_replace("\xC2\xA0", ' ', $address); // Replace non-breaking spaces

                $phone = $owner[14] ?? null;
                $phone = preg_replace('/[^\x20-\x7E]/u', '', $phone);
                $phone = str_replace("\xC2\xA0", ' ', $phone); // Replace non-breaking spaces

                $usersData = [
                    'email' => $ownerEmail,
                    'name' => trim(($owner[13] ?? '') . ' ' . ($owner[15] ?? '')),
                    'phone' => $phone,
                    'company' => $owner[8] ?? null,
                    'address' => $address,
                    'password' => bcrypt($ownerEmail),
                ];

                $userId = $this->createOrUpdateUser($usersData, 'owner');

                // Prepare owner data for bulk insertion
                $chunks[] = [
                    $owner_propertyware_id, // propertyware_id
                    $owner[17] ?? null,
                    $owner[18] ?? null,                   
                    $owner[13] ?? null,     // first_name
                    $owner[15] ?? null,     // last_name
                    $ownerEmail,            // email
                    $owner[16] ?? null,     // fax
                    $owner[17] ?? null,     // pager
                    $owner[14] ?? null,     // home_phone
                    $owner[24] ?? null,     // work_phone
                    $owner[16] ?? null,     // mobile_phone (this is likely a mistake since you have $owner[16] for fax)
                    $owner[4] ?? null,      // address
                    $owner[5] ?? null,      // address2
                    $owner[7] ?? null,      // city
                    $owner[22] ?? null,     // state
                    $owner[10] ?? null,     // country
                    $owner[25] ?? null,     // zip
                    $owner[8] ?? null,      // company
                    0,                  // is_property_restricted (defaulting to false)
                    0,                  // status (defaulting to false)
                    null,     // org_id
                    $owner[9] ?? null,      // taxID
                    $userId,                // user_id (from createOrUpdateUser method)
                    $now,                   // created_at (current timestamp)
                    $now,                   // updated_at (current timestamp)
                ];
                

                if (count($chunks) == $chunkSize) {
                    $stmt->execute(array_merge(...$chunks));
                    $chunks = [];
                    $chunkedPropertywareIds[] = $owner_propertyware_id;

                }
            }

            // Insert remaining rows
            if (!empty($chunks)) {
                $remainingRows = count($chunks);
                $stmt = $this->prepareChunkedStatementOwners($remainingRows);
                $stmt->execute(array_merge(...$chunks));
            }
        } finally {
            fclose($handle);
        }
    }

    private function createOrUpdateUser(array $data, string $role): int
    {
        static $existingUsers = null;

        if ($existingUsers === null) {
            $existingUsers = User::pluck('id', 'email')->mapWithKeys(function ($id, $email) {
                return [strtolower($email) => $id];
            })->toArray();
        }

        $email = strtolower($data['email']);

        if (isset($existingUsers[$email])) {
            $userId = $existingUsers[$email];

            // You can optionally ensure the role is assigned (in case it was missed before)
            $user = User::find($userId);
            if (!$user->hasRole($role)) {
                $user->assignRole($role);
            }

        } else {
            $user = User::create($data);
            $user->assignRole($role); // 🎯 Here’s the role being used
            $userId = $user->id;

            // 🔁 Update the cache
            $existingUsers[$email] = $userId;
        }

        return $userId;
    }


    private function prepareChunkedStatementOwners($chunkSize)
    {
        // Prepare row placeholders with 22 placeholders for each row
        $rowPlaceholders = '(?, ?, ?,?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        // Repeat placeholders for the chunk size
        $placeholders = implode(',', array_fill(0, $chunkSize, $rowPlaceholders));

        // Prepare the query for bulk insert with 22 columns
        $query = DB::connection()->getPdo()->prepare("
            INSERT INTO owners (
                propertyware_id,name, name_on_check, first_name, last_name, email, fax, pager, home_phone, work_phone, 
                mobile_phone, address, address2, city, state, country, zip, company, 
                is_property_restricted, status, org_id,tax_id, user_id, created_at, updated_at
            ) VALUES " . $placeholders
        );

        return $query;
    }

}
