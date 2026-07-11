<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Roles & Permissions first
        $this->call(RolePermissionSeeder::class);

        // Super Admin
        $superAdmin = User::factory()->create([
            'name' => 'Marjus Bushi',
            'email' => 'marjusbushi@zeroabsolute.com',
            'password' => bcrypt('Zero.Absolute1'),
        ]);
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $superAdmin->assignRole('admin');

        // Demo Admin
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@chanelmanager.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        // Demo staff users
        $manager = User::factory()->create([
            'name' => 'Erion Hoxha',
            'email' => 'manager@chanelmanager.com',
            'password' => bcrypt('password'),
        ]);
        $manager->assignRole('manager');

        $receptionist = User::factory()->create([
            'name' => 'Elisa Dervishi',
            'email' => 'reception@chanelmanager.com',
            'password' => bcrypt('password'),
        ]);
        $receptionist->assignRole('receptionist');

        $housekeeper = User::factory()->create([
            'name' => 'Fatmir Kola',
            'email' => 'housekeeping@chanelmanager.com',
            'password' => bcrypt('password'),
        ]);
        $housekeeper->assignRole('housekeeping');

        $barStaff = User::factory()->create([
            'name' => 'Arta Shehu',
            'email' => 'bar@chanelmanager.com',
            'password' => bcrypt('password'),
        ]);
        $barStaff->assignRole('pos_staff');

        // Rooms
        $this->call(RoomSeeder::class);

        // Guests
        $this->call(GuestSeeder::class);

        // Reservations
        $this->call(ReservationSeeder::class);

        // Cleaning Tasks
        $this->call(CleaningTaskSeeder::class);

        // POS Menu
        $this->call(MenuSeeder::class);

        // Settings
        $this->call(SettingSeeder::class);

        // Recurring demand events for pricing (holidays + August peak)
        $this->call(PricingEventSeeder::class);
    }
}
