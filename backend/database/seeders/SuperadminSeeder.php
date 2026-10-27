<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Crea el superadministrador desde SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD (si están definidos). */
final class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('predictily.superadmin.email');
        $password = config('predictily.superadmin.password');
        if (! $email || ! $password) {
            $this->command?->warn('SUPERADMIN_EMAIL/SUPERADMIN_PASSWORD no definidos: no se crea superadministrador.');

            return;
        }
        $user = User::query()->firstOrNew(['email' => mb_strtolower($email)]);
        $user->fill(['name' => 'Superadministrador Predictily', 'password' => $password]);
        $user->forceFill(['is_superadmin' => true])->save();
    }
}
