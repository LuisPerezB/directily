<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semilla base: planes y superadministrador. Los datos de demostración (ficticios) se cargan
 * aparte con `php artisan predictily:demo`, que usa el pipeline real de importación.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, SuperadminSeeder::class]);
    }
}
