<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OwnerSeeder_copy extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Read CSV file
        $csvFile = public_path('owners.csv'); 
        
        if (!File::exists($csvFile)) {
            Log::error('CSV file not found.');
            return;
        }

        $file = fopen($csvFile, 'r');

        // Skip the first row (header)
        fgetcsv($file);

        // Fetch existing propertyware_ids once to reduce DB queries
        $existingOwners = DB::table('owners')->pluck('propertyware_id')->toArray(); 

        $ownersToInsert = [];

        while (($owner = fgetcsv($file)) !== false) {
            $owner_propertyware_id = $owner[3] ?? null; // _id (Propertyware ID)

            if (empty($owner_propertyware_id) || in_array($owner_propertyware_id, $existingOwners)) {
                continue; // Skip if propertyware_id is empty or already exists
            }

            // Set email
            $ownerEmail = $owner[12] ?? $owner_propertyware_id . "@texasrenter.com";  

            // Construct address
            $address = trim(implode(' ', array_filter([
                $owner[4] ?? null,  // address
                $owner[5] ?? null,  // address2
                $owner[7] ?? null,  // city
                $owner[22] ?? null, // state
                $owner[10] ?? null, // country
                $owner[25] ?? null, // zip
            ])));

            // User data for creating/updating
            $usersData = [
                'email' => $ownerEmail,
                'name' => trim(($owner[13] ?? '') . ' ' . ($owner[15] ?? '')),  // firstName + lastName
                'phone' => $owner[14] ?? null,  // homePhone
                'company' => $owner[8] ?? null, // companyName
                'address' => $address,
                'password' => bcrypt($ownerEmail),
            ];

            // Creating or updating user
            $user = $this->createOrUpdateUser($usersData, 'owner');

            $ownersToInsert[] = [
                'propertyware_id' => $owner_propertyware_id,
                'first_name' => $owner[13] ?? null,
                'last_name' => $owner[15] ?? null,
                'email' => $ownerEmail,
                'fax' => $owner[16] ?? null,
                'pager' => $owner[17] ?? null,
                'home_phone' => $owner[14] ?? null,
                'work_phone' => $owner[24] ?? null,
                'mobile_phone' => $owner[16] ?? null, // Ensure correct mobile field
                'address' => $owner[4] ?? null,
                'address2' => $owner[5] ?? null,
                'city' => $owner[7] ?? null,
                'state' => $owner[22] ?? null,
                'country' => $owner[10] ?? null,
                'zip' => $owner[25] ?? null,
                'company' => $owner[8] ?? null,
                'is_property_restricted' => false, 
                'status' => $owner[23] ?? null,
                'org_id' => $owner[9] ?? null,
                'user_id' => $user->id,
            ];

            // Batch insert every 100 records
            if (count($ownersToInsert) >= 100) {
                DB::table('owners')->insert($ownersToInsert);
                $ownersToInsert = []; // Reset array after insert
            }
        }

        // Insert remaining owners
        if (!empty($ownersToInsert)) {
            DB::table('owners')->insert($ownersToInsert);
        }

        fclose($file);
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        // Check if user exists, if not create new one
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);
        return $user;
    }
}
