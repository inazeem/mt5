<?php

namespace Database\Seeders;

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
        $ownerEmail = (string) env('APP_OWNER_EMAIL', 'test@example.com');
        $ownerPassword = (string) env('APP_OWNER_PASSWORD', 'password');

        User::query()->firstOrCreate([
            'email' => $ownerEmail,
        ], [
            'name' => 'Owner',
            'email_verified_at' => now(),
            'password' => Hash::make($ownerPassword),
        ]);

        $this->call([
            ForexTickerSeeder::class,
        ]);
    }
}
