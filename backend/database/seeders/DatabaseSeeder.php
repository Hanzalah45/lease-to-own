<?php

namespace Database\Seeders;

use App\Models\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Super admin — the client. Unrestricted by definition; not created
        // through the admin-users endpoint, only ever seeded/bootstrapped.
        User::create([
            'name' => 'Joel Stebbins',
            'email' => 'superadmin@outdoorfix.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        // Admin with no restriction rows — full access by default.
        User::create([
            'name' => 'Outdoor Fix Admin',
            'email' => 'admin@outdoorfix.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        // Admin restricted (opt-in) to just two modules, to demo the restriction path.
        $restrictedAdmin = User::create([
            'name' => 'Equipment & Payments Admin',
            'email' => 'restricted.admin@outdoorfix.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);
        foreach ([AdminPermission::EQUIPMENT_TRACKING, AdminPermission::PAYMENT_TRACKING] as $permission) {
            AdminPermission::create(['user_id' => $restrictedAdmin->id, 'permission' => $permission]);
        }

        // Dummy customer account for local testing / demoing the customer portal.
        $customer = User::create([
            'name' => 'Test Customer',
            'email' => 'customer@outdoorfix.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_CUSTOMER,
            'status' => 'active',
        ]);
        CustomerProfile::create(['user_id' => $customer->id]);
    }
}
