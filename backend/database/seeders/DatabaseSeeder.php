<?php

namespace Database\Seeders;

use App\Models\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\EquipmentUnit;
use App\Models\User;
use App\Notifications\AdminAccountCreatedNotification;
use App\Notifications\NewCustomerRegisteredNotification;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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

        // Starter inventory so the equipment module has serial numbers to work
        // with on a fresh install. All in stock — units become `leased` only by
        // being assigned to a lease through the equipment endpoints.
        foreach ([
            ['model' => 'Worldlawn Diamondback 60"', 'serial_number' => 'AGZ3WA18973', 'condition_notes' => 'New / 2026'],
            ['model' => 'Worldlawn Gator 34"', 'serial_number' => '202303U13213', 'condition_notes' => 'New / 2026'],
            ['model' => 'Ferris IS3200Z', 'serial_number' => 'FRS220091', 'vin' => '1FRS220091X0042', 'condition_notes' => 'New / 2026'],
            ['model' => 'Scag Turf Tiger', 'serial_number' => 'STT550112', 'condition_notes' => 'Used / 2024 — 320 hrs'],
            ['model' => 'Exmark Lazer Z X-Series', 'serial_number' => 'EXM770233', 'condition_notes' => 'New / 2026'],
        ] as $unit) {
            EquipmentUnit::create($unit + ['status' => EquipmentUnit::STATUS_IN_STOCK]);
        }

        // Seed a couple of real notifications (same classes the live triggers use)
        // so the notification feeds aren't empty on first login.
        $staff = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->get();
        Notification::send($staff, new NewCustomerRegisteredNotification($customer));
        $restrictedAdmin->notify(new AdminAccountCreatedNotification());
    }
}
