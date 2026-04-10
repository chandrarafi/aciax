<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the admin/demo user
        User::factory()->create([
            'name' => 'Sat Naing',
            'email' => 'satnaingdev@gmail.com',
            'password' => Hash::make('password'),
        ]);

        // Create additional test users
        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password'),
        ]);

        User::factory()->create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'password' => Hash::make('password'),
        ]);

        // Create 10 random users
        User::factory(10)->create();
    }
}
