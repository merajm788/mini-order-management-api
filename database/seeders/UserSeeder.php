<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Two known accounts so the README can document working credentials.
        User::query()->updateOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Merchant', 'password' => 'password123'],
        );

        User::query()->updateOrCreate(
            ['email' => 'customer@example.com'],
            ['name' => 'Demo Customer', 'password' => 'password123'],
        );

        User::factory()->count(8)->create();
    }
}
