<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Super Admin User
        User::updateOrCreate(
            ['email' => 'admin@hubai.com.br'],
            [
                'name' => 'Super Admin HubAI',
                'password' => Hash::make('12345678'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );

        // Call the DummyDataSeeder to create clients, suppliers and orders
        $this->call([
            DummyDataSeeder::class,
        ]);
    }
}
