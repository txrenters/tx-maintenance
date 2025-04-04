<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@texasrenter.com',
            'email_verified_at' => now(),
            'password' => bcrypt('admin@texasrenter.com'),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'remember_token' => Str::random(10),
            'profile_photo_path' => null,
            'current_team_id' => null,
        ]);

        $admin->assignRole('admin');
        $admin->givePermissionTo(Permission::get());

        $woc = User::create([
            'name' => 'Work Order Coordinator',
            'email' => 'woc@texasrenter.com',
            'email_verified_at' => now(),
            'password' => bcrypt('woc@texasrenter.com'),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'remember_token' => Str::random(10),
            'profile_photo_path' => null,
            'current_team_id' => null,
        ]);

        $woc->assignRole('woc');
        $admin->givePermissionTo(['view', 'create', 'edit']);

    }
}
