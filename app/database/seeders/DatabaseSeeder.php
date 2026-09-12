<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => strtolower((string) env('SEED_USER_EMAIL', 'admin@example.com'))],
            [
                'name' => env('SEED_USER_NAME', 'Admin'),
                'password' => env('SEED_USER_PASSWORD', 'password'),
            ],
        );
    }
}
