<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Planes SaaS iniciales (asignación manual por superadmin; sin pasarela de pagos en el MVP). */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('predictily.plans') as $code => $plan) {
            Plan::query()->updateOrCreate(['code' => $code], [
                'name' => $plan['name'],
                'description' => $plan['description'],
                'limits' => $plan['limits'],
                'monthly_price_usd' => $plan['monthly_price_usd'],
                'is_active' => true,
            ]);
        }
    }
}
