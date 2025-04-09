<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorService 
{
    public function handle($data)
    {
        DB::beginTransaction();

        try {

            $vendor_propertyware_id = $data['ID'] ?? null;
            $vendorEmail = $data['email'] ?? $vendor_propertyware_id.'@texasrenter.com';

            $vendorExist = Vendor::where('propertyware_id', $vendor_propertyware_id)->where('email', $vendorEmail)->exists();

            $userExist = User::where('email', $vendorEmail)->exists();

            if ($vendorExist || $userExist) {
                return null; // Vendor already exists, no need to create a new one
            }

            $address = trim(implode(' ', array_filter([
                $data['address'] ?? null,
                $data['address2'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['country'] ?? null,
                $data['zip'] ?? null,
            ])));

            $usersData = [
                'email' => $vendorEmail,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'company' => $data['companyName'] ?? null,
                'address' => $address,
                'password' => bcrypt($vendorEmail),
            ];

            $user = $this->createOrUpdateUser($usersData, 'vendor');
            
            $vendorsData = [
                'propertyware_id' => $user->id,
                'name' => $data['name'],
                'name_on_check' => $data['name'],
                'email' => $vendorEmail,
                'user_id' => $user->id,
                'is_active' => $data['active'],
            ];

            $vendor = Vendor::create($vendorsData);

            DB::commit();

            return $vendor;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating vendor: ' . $e->getMessage());
        }
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);

        return $user;
    }
}