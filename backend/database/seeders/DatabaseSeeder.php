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
        $superAdmin = User::create([
            'name' => 'Outdoor Fix Admin',
            'email' => 'admin@outdoorfix.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        foreach ([
            AdminPermission::APPLICATION_REVIEW,
            AdminPermission::RISK_ASSESSMENT,
            AdminPermission::CONTRACT_GENERATION,
            AdminPermission::EQUIPMENT_TRACKING,
            AdminPermission::PAYMENT_TRACKING,
        ] as $permission) {
            AdminPermission::create(['user_id' => $superAdmin->id, 'permission' => $permission]);
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
