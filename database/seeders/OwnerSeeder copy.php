<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OwnerSeederCopy extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(public_path('owners.json'));

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');
            return;
        } 
        
        $ownersToInsert = [];

        foreach(array_chunk($data, 100) as $chunkOwners){

    
            foreach ($chunkOwners as $owner) {
                $owner_propertyware_id = $owner['_id'];

                $checkOwner = DB::table('owners')->where('propertyware_id', $owner_propertyware_id)->exists();

                if (!$checkOwner) {
                    $ownerEmail = $owner['email'] ?? $owner_propertyware_id . "@texasrenter.com";

                    $address = trim(implode(' ', array_filter([
                        $owner['address'] ?? null,
                        $owner['address2'] ?? null,
                        $owner['city'] ?? null,
                        $owner['state'] ?? null,
                        $owner['country'] ?? null,
                        $owner['zip'] ?? null,
                    ])));

                    $usersData = [
                        'email' => $ownerEmail,
                        'name' => $owner['firstName'] . ' ' . $owner['lastName'],
                        'phone' => $owner['homePhone'] ?? null,
                        'company' => $owner['company'] ?? null,
                        'address' => $address,
                        'password' => bcrypt($ownerEmail),
                    ];

                    // Creating or updating user in bulk
                    $user = $this->createOrUpdateUser($usersData, 'owner');

                    $ownerData = [
                        'propertyware_id' => $owner_propertyware_id,
                        'first_name' => $owner['firstName'] ?? null,
                        'last_name' => $owner['lastName'] ?? null,
                        'email' => $ownerEmail,
                        'fax' => $owner['fax'] ?? null,
                        'pager' => $owner['pager'] ?? null,
                        'home_phone' => $owner['homePhone'] ?? null,
                        'work_phone' => $owner['workPhone'] ?? null,
                        'mobile_phone' => $owner['mobile'] ?? null,
                        'address' => $owner['address'] ?? null,
                        'address2' => $owner['address2'] ?? null,
                        'city' => $owner['city'] ?? null,
                        'state' => $owner['state'] ?? null,
                        'country' => $owner['country'] ?? null,
                        'zip' => $owner['zip'] ?? null,
                        'company' => $owner['company'] ?? null,
                        'is_property_restricted' => $owner['propertyRestricted'] ?? null,
                        'status' => $owner['status'] ?? null,
                        'org_id' => $owner['orgId'] ?? null,
                        'user_id' => $user->id,
                    ];

                    $ownersToInsert[] = $ownerData;
                }
            }

            // Perform a batch insert for tenants
            if (!empty($ownersToInsert)) {
                DB::table('owners')->insert($ownersToInsert);
            }
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
