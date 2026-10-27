<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Tenant;
use App\Services\Imports\Support\Issue;
use App\Support\Privacy\CustomerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resuelve y (opcionalmente) crea sucursales, categorías, productos, clientes y
 * proveedores de una empresa con operaciones masivas (sin Eloquent fila a fila).
 * Mantiene mapas en memoria durante una importación.
 */
final class CatalogResolver
{
    /** @var array<string, int> código normalizado => id */
    private array $branches;

    /** @var array<string, int> sku normalizado => id (incluye productos eliminados lógicamente) */
    private array $products;

    /** @var array<string, int> nombre normalizado => id */
    private array $categories;

    /** @var array<string, true> */
    private array $pendingProducts = [];

    /** @var array<string, true> */
    private array $pendingBranches = [];

    /** @var array<string, true> */
    private array $reported = [];

    /** @param array<string, mixed> $options */
    public function __construct(private readonly Tenant $tenant, private readonly array $options = [])
    {
        $this->branches = $this->normalizedMap(DB::table('branches')->where('tenant_id', $tenant->id)->pluck('id', 'code')->all());
        $this->products = $this->normalizedMap(DB::table('products')->where('tenant_id', $tenant->id)->pluck('id', 'sku')->all());
        $this->categories = $this->normalizedMap(DB::table('product_categories')->where('tenant_id', $tenant->id)->pluck('id', 'name')->all());
    }

    public static function norm(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    private function option(string $key, bool $default): bool
    {
        return (bool) ($this->options[$key] ?? $default);
    }

    /** Sucursal por defecto: opción explícita, o la única sucursal existente. */
    public function defaultBranchCode(): ?string
    {
        if (! empty($this->options['default_branch_code'])) {
            return (string) $this->options['default_branch_code'];
        }
        if (count($this->branches) === 1) {
            return (string) array_key_first($this->branches);
        }
        if ($this->branches === [] && $this->option('create_missing_branches', false)) {
            return 'PRINCIPAL';
        }

        return null;
    }

    /**
     * Valida/resuelve el código de sucursal de una fila.
     *
     * @return array{0: ?string, 1: list<Issue>} [código resuelto, issues]
     */
    public function checkBranch(?string $code, string $column = 'branch_code'): array
    {
        $code ??= $this->defaultBranchCode();
        if ($code === null) {
            return [null, [Issue::error('required', 'Falta el código de sucursal (la empresa tiene varias sucursales; defina una sucursal por defecto o mapee la columna).', $column)]];
        }
        $key = self::norm($code);
        if (isset($this->branches[$key]) || isset($this->pendingBranches[$key])) {
            return [$code, []];
        }
        if ($this->option('create_missing_branches', false)) {
            $this->pendingBranches[$key] = true;

            return [$code, $this->once('branch:'.$key, Issue::warning('unknown_branch', "La sucursal '{$code}' no existe y se creará.", $column, $code))];
        }

        return [null, [Issue::error('unknown_branch', "La sucursal '{$code}' no existe en la empresa.", $column, $code)]];
    }

    /** @return list<Issue> */
    public function checkProduct(string $sku, string $column = 'product_sku'): array
    {
        $key = self::norm($sku);
        if (isset($this->products[$key]) || isset($this->pendingProducts[$key])) {
            return [];
        }
        if ($this->option('create_missing_products', true)) {
            $this->pendingProducts[$key] = true;

            return $this->once('product:'.$key, Issue::warning('unknown_product', "El producto '{$sku}' no existe y se creará.", $column, $sku));
        }

        return [Issue::error('unknown_product', "El producto '{$sku}' no existe en la empresa.", $column, $sku)];
    }

    public function productsToCreate(): int
    {
        return count($this->pendingProducts);
    }

    public function branchesToCreate(): int
    {
        return count($this->pendingBranches);
    }

    public function branchId(string $code): ?int
    {
        return $this->branches[self::norm($code)] ?? null;
    }

    public function productId(string $sku): ?int
    {
        return $this->products[self::norm($sku)] ?? null;
    }

    public function hasProduct(string $sku): bool
    {
        return isset($this->products[self::norm($sku)]);
    }

    /**
     * Crea las sucursales faltantes.
     *
     * @param  list<string>  $codes
     * @return int creadas
     */
    public function ensureBranches(array $codes): int
    {
        $missing = [];
        foreach ($codes as $code) {
            $key = self::norm($code);
            if (! isset($this->branches[$key])) {
                $missing[$key] = $code;
            }
        }
        if ($missing === []) {
            return 0;
        }
        $now = now();
        DB::table('branches')->insertOrIgnore(array_map(fn ($code) => [
            'tenant_id' => $this->tenant->id,
            'code' => mb_substr($code, 0, 50),
            'name' => $code === 'PRINCIPAL' ? 'Sucursal principal' : 'Sucursal '.$code,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($missing)));
        $this->branches = $this->normalizedMap(DB::table('branches')->where('tenant_id', $this->tenant->id)->pluck('id', 'code')->all());

        return count($missing);
    }

    /**
     * @param  list<string>  $names
     * @return int creadas
     */
    public function ensureCategories(array $names): int
    {
        $missing = [];
        foreach ($names as $name) {
            if ($name === null || trim($name) === '') {
                continue;
            }
            $key = self::norm($name);
            if (! isset($this->categories[$key])) {
                $missing[$key] = trim($name);
            }
        }
        if ($missing === [] || ! $this->option('create_missing_categories', true)) {
            return 0;
        }
        $now = now();
        DB::table('product_categories')->insertOrIgnore(array_map(fn ($name) => [
            'tenant_id' => $this->tenant->id,
            'name' => mb_substr($name, 0, 150),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($missing)));
        $this->categories = $this->normalizedMap(DB::table('product_categories')->where('tenant_id', $this->tenant->id)->pluck('id', 'name')->all());

        return count($missing);
    }

    public function categoryId(?string $name): ?int
    {
        return $name === null ? null : ($this->categories[self::norm($name)] ?? null);
    }

    /**
     * Crea productos faltantes a partir de [sku => ['name' => ?, 'category' => ?, 'unit' => ?]].
     *
     * @param  array<string, array{name: ?string, category: ?string}>  $products
     * @return int creados
     */
    public function ensureProducts(array $products): int
    {
        $missing = [];
        foreach ($products as $sku => $info) {
            $key = self::norm((string) $sku);
            if (! isset($this->products[$key]) && ! isset($missing[$key])) {
                $missing[$key] = ['sku' => (string) $sku] + $info;
            }
        }
        if ($missing === []) {
            return 0;
        }
        $this->ensureCategories(array_values(array_filter(array_column($missing, 'category'))));
        $now = now();
        foreach (array_chunk(array_values($missing), 1000) as $chunk) {
            DB::table('products')->insertOrIgnore(array_map(fn ($p) => [
                'tenant_id' => $this->tenant->id,
                'sku' => mb_substr($p['sku'], 0, 100),
                'name' => mb_substr($p['name'] ?: $p['sku'], 0, 255),
                'category_id' => $this->categoryId($p['category'] ?? null),
                'unit' => 'unidad',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
        $skus = array_map(fn ($p) => $p['sku'], array_values($missing));
        foreach (array_chunk($skus, 1000) as $chunk) {
            foreach (DB::table('products')->where('tenant_id', $this->tenant->id)->whereIn('sku', $chunk)->pluck('id', 'sku') as $sku => $id) {
                $this->products[self::norm((string) $sku)] = (int) $id;
            }
        }
        // Puede haber diferencias de mayúsculas con SKUs existentes: recargar para resolverlas.
        if (count(array_filter($skus, fn ($s) => ! isset($this->products[self::norm($s)]))) > 0) {
            $this->products = $this->normalizedMap(DB::table('products')->where('tenant_id', $this->tenant->id)->pluck('id', 'sku')->all());
        }

        return count($missing);
    }

    /**
     * Resuelve/crea clientes. Cédulas y RUC se convierten en huella HMAC (nunca en claro).
     *
     * @param  array<string, array{segment: ?string, province: ?string}>  $customers  código original => datos
     * @return array<string, int> código original => customer_id
     */
    public function ensureCustomers(array $customers): array
    {
        if ($customers === []) {
            return [];
        }
        $segments = [];
        foreach ($customers as $info) {
            if (! empty($info['segment'])) {
                $segments[Str::limit(Str::slug($info['segment'], '_'), 50, '')] = $info['segment'];
            }
        }
        $segmentIds = [];
        if ($segments !== []) {
            $now = now();
            DB::table('customer_segments')->insertOrIgnore(array_map(fn ($code, $name) => [
                'tenant_id' => $this->tenant->id, 'code' => $code ?: 'segmento', 'name' => mb_substr($name, 0, 150), 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($segments), array_values($segments)));
            $segmentIds = DB::table('customer_segments')->where('tenant_id', $this->tenant->id)->whereIn('code', array_keys($segments))->pluck('id', 'code')->all();
        }

        $rows = [];
        $externalByOriginal = [];
        $now = now();
        foreach ($customers as $code => $info) {
            $identity = CustomerIdentity::fromCode((string) $code);
            $externalByOriginal[(string) $code] = $identity['external_code'];
            $segmentCode = ! empty($info['segment']) ? Str::limit(Str::slug($info['segment'], '_'), 50, '') : null;
            $rows[$identity['external_code']] = [
                'tenant_id' => $this->tenant->id,
                'external_code' => $identity['external_code'],
                'customer_type' => $identity['customer_type'],
                'tax_id_hash' => $identity['tax_id_hash'],
                'segment_id' => $segmentCode ? ($segmentIds[$segmentCode] ?? null) : null,
                'province' => $info['province'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($rows), 1000) as $chunk) {
            DB::table('customers')->insertOrIgnore($chunk);
        }
        $ids = [];
        foreach (array_chunk(array_keys($rows), 1000) as $chunk) {
            $ids += DB::table('customers')->where('tenant_id', $this->tenant->id)->whereIn('external_code', $chunk)->pluck('id', 'external_code')->all();
        }

        $result = [];
        foreach ($externalByOriginal as $original => $external) {
            if (isset($ids[$external])) {
                $result[$original] = (int) $ids[$external];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, int>
     */
    private function normalizedMap(array $map): array
    {
        $result = [];
        foreach ($map as $key => $id) {
            $result[self::norm((string) $key)] = (int) $id;
        }

        return $result;
    }

    /** Reporta una advertencia solo la primera vez para una clave (evita miles de filas repetidas). */
    private function once(string $key, Issue $issue): array
    {
        if (isset($this->reported[$key])) {
            return [];
        }
        $this->reported[$key] = true;

        return [$issue];
    }
}
