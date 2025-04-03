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
        // Read CSV file
        $csvFile = public_path('owners.csv'); // Change to your CSV file path
        
        if (!File::exists($csvFile)) {
            Log::error('CSV file not found.');
            return;
        }

        $file = fopen($csvFile, 'r');

        // Skip the first row (header)
        fgetcsv($file);

        $ownersToInsert = [];

        $existingOwner = DB::table('owners')->pluck('propertyware_id')->toArray(); // Fetch existing propertyware_ids once


        while (($owner = fgetcsv($file)) !== false) {

            // Assuming the CSV columns match:
            // 0 -> id, 1 -> workOrderId, 2 -> clientData, 3 -> _id, 4 -> address, 5 -> address2, 
            // 6 -> altPhone, 7 -> city, 8 -> companyName, 9 -> contactId, 10 -> country, 
            // 11 -> customFields, 12 -> email, 13 -> firstName, 14 -> homePhone, 
            // 15 -> lastName, 16 -> mobile, 17 -> name, 18 -> nameOnCheck, 19 -> notes, 
            // 20 -> percentageOwnership, 21 -> phone, 22 -> state, 23 -> taxID, 
            // 24 -> workTelephone, 25 -> zip, 26 -> created_at, 27 -> updated_at
            
            $owner_propertyware_id = $owner[3]; // _id (Propertyware ID)

            if (empty($owner_propertyware_id)) {  // 3rd column is propertyware_id (_id)
                continue;  // Skip if propertyware_id is empty
            }

            if (in_array($owner_propertyware_id, $existingOwner)) {
                continue; // skip if exists in existing tenants
            }

            $ownerEmail = $owner[12] ?? $owner_propertyware_id . "@texasrenter.com";  // email

            // Constructing the address
            $address = trim(implode(' ', array_filter([
                $owner[4] ?? null,  // address
                $owner[5] ?? null,  // address2
                $owner[7] ?? null,  // city
                $owner[22] ?? null, // state
                $owner[10] ?? null, // country
                $owner[25] ?? null, // zip
            ])));

            $usersData = [
                'email' => $ownerEmail,
                'name' => $owner[13] . ' ' . $owner[15],  // firstName + lastName
                'phone' => $owner[14] ?? null,  // homePhone
                'company' => $owner[8] ?? null, // companyName
                'address' => $address,
                'password' => bcrypt($ownerEmail),
            ];

            // Creating or updating user
            $user = $this->createOrUpdateUser($usersData, 'owner');

            $ownerData = [
                'propertyware_id' => $owner_propertyware_id,
                'first_name' => $owner[13] ?? null,
                'last_name' => $owner[15] ?? null,
                'email' => $ownerEmail,
                'fax' => $owner[16] ?? null,
                'pager' => $owner[17] ?? null,
                'home_phone' => $owner[14] ?? null,
                'work_phone' => $owner[24] ?? null,
                'mobile_phone' => $owner[16] ?? null,
                'address' => $owner[4] ?? null,
                'address2' => $owner[5] ?? null,
                'city' => $owner[7] ?? null,
                'state' => $owner[22] ?? null,
                'country' => $owner[10] ?? null,
                'zip' => $owner[25] ?? null,
                'company' => $owner[8] ?? null,
                'is_property_restricted' => false, // Custom fields
                'status' => $owner[23] ?? null, // status
                'org_id' => $owner[9] ?? null, // orgId
                'user_id' => $user->id,
            ];

            $ownersToInsert[] = $ownerData;
        }

        fclose($file);

        // Perform a batch insert for owners
        if (!empty($ownersToInsert)) {
            DB::table('owners')->insert($ownersToInsert);
        }
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        // Check if user exists, if not create new one
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);
        return $user;
    }
}
