<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Compradores FICTICIOS para una empresa que ya tiene el catálogo de AnalyticsFixtures (sucursales y SKU P1-SKU/P2-SKU). */
trait SeedsCustomerCatalogOnExistingTenant
{
    /** @return array{branch: int, products: array<string, int>, customers: array<string, int>} */
    protected function seedCustomerCatalogFor(Tenant $tenant, string $customerCode = 'CLI-0001'): array
    {
        $now = now();
        $tid = $tenant->id;
        $branch = (int) DB::table('branches')->where('tenant_id', $tid)->value('id');
        $products = [
            'p1' => (int) DB::table('products')->where('tenant_id', $tid)->where('sku', 'P1-SKU')->value('id'),
            'p2' => (int) DB::table('products')->where('tenant_id', $tid)->where('sku', 'P2-SKU')->value('id'),
        ];
        $customers = [];
        foreach (['c1' => [$customerCode, 'company', false], 'c2' => ['CLI-0002', 'person', false], 'c3' => ['9999999999999', 'final_consumer', true]] as $key => [$code, $type, $generic]) {
            $customers[$key] = (int) DB::table('customers')->insertGetId([
                'tenant_id' => $tid, 'external_code' => $code, 'customer_type' => $type, 'is_generic' => $generic, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['branch' => $branch, 'products' => $products, 'customers' => $customers];
    }
}
