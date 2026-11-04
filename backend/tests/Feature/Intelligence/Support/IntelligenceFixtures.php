<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence\Support;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FactorCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;

/** Datos FICTICIOS y ayudas comunes de las pruebas de Factor Intelligence (W2-F). */
trait IntelligenceFixtures
{
    use InteractsWithTenants;

    protected function seedFactorCatalog(): void
    {
        $this->seed(FactorCatalogSeeder::class);
    }

    /** Propietario de la plataforma (superadministrador): sin empresa, sin encabezado X-Tenant-ID. */
    protected function actingAsSuperadmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        $this->flushHeaders();
        Sanctum::actingAs($admin->refresh(), ['*']);

        return $admin;
    }

    protected function insertProduct(Tenant $tenant, string $sku = 'CEM-01', string $name = 'Cemento 50kg', ?int $categoryId = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id, 'sku' => $sku, 'name' => $name, 'unit' => 'saco', 'category_id' => $categoryId, 'is_active' => true,
            'current_price' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function insertCategory(Tenant $tenant, string $name = 'Cementos'): int
    {
        return (int) DB::table('product_categories')->insertGetId(['tenant_id' => $tenant->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }
}
