<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccountingRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Check if accounting role already exists
        $accountingRole = Role::firstOrCreate(['name' => 'accounting']);

        $accountingRole->givePermissionTo(['view', 'create', 'edit']);

        $this->command->info('Accounting role created/updated successfully!');
    }
}
