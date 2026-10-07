<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a working world: the simulated marketplace, our catalogue and a login.
     * Safe to run repeatedly.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // Catalogue first: the marketplace publishes a snapshot notification per listing when it is
        // created, and those must find our products to be priced.
        $this->call([CatalogSeeder::class, MarketplaceSeeder::class]);
    }
}
