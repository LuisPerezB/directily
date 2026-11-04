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
 *
 * Identidades (ADR-V25-24): ANTES de tratar un código como producto o cliente nuevo se consultan `product_aliases` y
 * `customer_aliases`: primero el alias específico de la fuente (`options['data_source_id']`), luego el general
 * (`data_source_id` NULL), y solo si su vigencia cubre la fecha de resolución (`$on`; por defecto hoy en la zona horaria de
 * la empresa). Un alias nunca reescribe filas históricas: redirige el código a la identidad canónica. Fusión y división de
 * identidades (`kind` merge/split) están DISEÑADAS y no se aplican aquí.
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

    /** @var array<string, list<array{id: int, target: int, source: ?int, from: ?string, to: ?string}>> código normalizado => alias */
    private array $productAliases;

    /** @var array<string, list<array{id: int, target: int, source: ?int, from: ?string, to: ?string}>> external_code => alias */
    private array $customerAliases;

    /** @var array<string, string> código original => external_code (minimizado), memoria de la resolución por documento */
    private array $externalCodes = [];

    private ?int $sourceId;

    private string $today;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly Tenant $tenant, private readonly array $options = [])
    {
        $this->branches = $this->normalizedMap(DB::table('branches')->where('tenant_id', $tenant->id)->pluck('id', 'code')->all());
        $this->products = $this->normalizedMap(DB::table('products')->where('tenant_id', $tenant->id)->pluck('id', 'sku')->all());
        $this->categories = $this->normalizedMap(DB::table('product_categories')->where('tenant_id', $tenant->id)->pluck('id', 'name')->all());
        $this->sourceId = isset($options['data_source_id']) ? (int) $options['data_source_id'] : null;
        $this->today = now($tenant->timezone ?: 'UTC')->toDateString();
        $this->productAliases = $this->loadAliases('product_aliases', 'product_id', true);
        $this->customerAliases = $this->loadAliases('customer_aliases', 'customer_id', false);
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
    public function checkProduct(string $sku, string $column = 'product_sku', ?string $on = null): array
    {
        $key = self::norm($sku);
        if ($this->productId($sku, $on) !== null || isset($this->pendingProducts[$key])) {
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

    /** Id del producto de un código de origen: PRIMERO el alias vigente en `$on` (fuente específica, luego general) y después el SKU. */
    public function productId(string $sku, ?string $on = null): ?int
    {
        $key = self::norm($sku);

        return $this->matchAlias($this->productAliases[$key] ?? [], $on) ?? ($this->products[$key] ?? null);
    }

    public function hasProduct(string $sku, ?string $on = null): bool
    {
        return $this->productId($sku, $on) !== null;
    }

    /** Solo la resolución por alias (sin consultar el SKU): para API y pruebas. */
    public function productIdByAlias(string $code, ?string $on = null): ?int
    {
        return $this->matchAlias($this->productAliases[self::norm($code)] ?? [], $on);
    }

    /** SKU vigente (con su mayúsculas/minúsculas) de un producto de la empresa, incluidos los dados de baja; null si no existe. */
    public function canonicalSku(int $productId): ?string
    {
        $sku = DB::table('products')->where('tenant_id', $this->tenant->id)->where('id', $productId)->value('sku');

        return $sku === null ? null : (string) $sku;
    }

    /** ¿Algún alias (de cualquier vigencia o fuente) usa este código de producto? Permite a quien escribe por documento juntar sus fechas solo cuando importan. */
    public function hasAliasFor(string $sku): bool
    {
        return isset($this->productAliases[self::norm($sku)]);
    }

    public function hasCustomerAliases(): bool
    {
        return $this->customerAliases !== [];
    }

    /**
     * Id del cliente de un documento: el alias vigente en la fecha del documento (`$on`, p. ej. `sale_date`) o, si no hay, el id que
     * `ensureCustomers` devolvió para ese código. Sin alias de clientes en la empresa es una simple consulta del mapa.
     *
     * @param  array<string, int>  $ids  resultado de ensureCustomers
     */
    public function customerIdFor(string $code, ?string $on, array $ids): ?int
    {
        if ($this->customerAliases === []) {
            return $ids[$code] ?? null;
        }

        return $this->customerIdByAlias($this->externalCode($code), $on) ?? ($ids[$code] ?? null);
    }

    private function externalCode(string $code): string
    {
        return $this->externalCodes[$code] ??= CustomerIdentity::fromCode($code)['external_code'];
    }

    /** Id del cliente al que apunta un alias vigente del código ya minimizado (`customers.external_code`), o null. */
    public function customerIdByAlias(string $externalCode, ?string $on = null): ?int
    {
        return $this->matchAlias($this->customerAliases[$externalCode] ?? [], $on);
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
     * Crea productos faltantes a partir de [sku => ['name' => ?, 'category' => ?, 'dates' => [Y-m-d => true]?]].
     *
     * @param  array<string, array{name: ?string, category: ?string, dates?: array<string, true>}>  $products
     * @return int creados
     */
    public function ensureProducts(array $products): int
    {
        $missing = [];
        foreach ($products as $sku => $info) {
            $key = self::norm((string) $sku);
            // Un código con alias vigente ya tiene identidad: no se crea un producto nuevo (ADR-V25-24). Con `dates` (fechas de los
            // documentos que lo usan) se exige que resuelva en TODAS; basta una fecha sin alias vigente ni SKU para crearlo.
            if (! isset($missing[$key]) && ! $this->productResolvesOn((string) $sku, array_keys((array) ($info['dates'] ?? [])))) {
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

    /** Cliente al que apunta el alias del código si rige en todas las fechas dadas (hoy si no hay); null si en alguna no rige. */
    private function aliasTargetOnAll(string $externalCode, array $dates): ?int
    {
        if ($dates === []) {
            return $this->customerIdByAlias($externalCode);
        }
        $target = null;
        foreach ($dates as $date) {
            $id = $this->customerIdByAlias($externalCode, (string) $date);
            if ($id === null) {
                return null;
            }
            $target ??= $id;
        }

        return $target;
    }

    /** ¿El código resuelve (alias vigente o SKU) en todas las fechas dadas (hoy si no hay)? */
    private function productResolvesOn(string $sku, array $dates): bool
    {
        if ($dates === []) {
            return $this->productId($sku) !== null;
        }
        foreach ($dates as $date) {
            if ($this->productId($sku, (string) $date) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resuelve/crea clientes. Cédulas y RUC se convierten en huella HMAC (nunca en claro). Un código con alias vigente apunta al
     * cliente existente; con `dates` (fechas de los documentos que lo usan) el alias debe regir en TODAS, y si no, también se crea el
     * cliente del código directo (quien escribe por documento elige con `customerIdFor`).
     *
     * @param  array<string, array{segment: ?string, province: ?string, dates?: array<string, true>}>  $customers  código original => datos
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
        $aliased = [];
        $now = now();
        foreach ($customers as $code => $info) {
            $identity = CustomerIdentity::fromCode((string) $code);
            // Un código con alias vigente apunta a un cliente existente: no se crea otra identidad (ADR-V25-24).
            $target = $this->aliasTargetOnAll($identity['external_code'], array_keys((array) ($info['dates'] ?? [])));
            if ($target !== null) {
                $aliased[(string) $code] = $target;

                continue;
            }
            $externalByOriginal[(string) $code] = $identity['external_code'];
            $segmentCode = ! empty($info['segment']) ? Str::limit(Str::slug($info['segment'], '_'), 50, '') : null;
            $rows[$identity['external_code']] = [
                'tenant_id' => $this->tenant->id,
                'external_code' => $identity['external_code'],
                'customer_type' => $identity['customer_type'],
                // Código genérico (consumidor final): no representa un comprador recurrente (etapa 2, contrato canónico).
                'is_generic' => $identity['customer_type'] === 'final_consumer',
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

        $result = $aliased;
        foreach ($externalByOriginal as $original => $external) {
            if (isset($ids[$external])) {
                $result[$original] = (int) $ids[$external];
            }
        }

        return $result;
    }

    /**
     * Alias de la empresa agrupados por código. Las claves de producto se normalizan igual que los SKU; las de cliente son el
     * código ya minimizado, tal cual (customers.external_code distingue mayúsculas).
     *
     * @return array<string, list<array{id: int, target: int, source: ?int, from: ?string, to: ?string}>>
     */
    private function loadAliases(string $table, string $targetColumn, bool $normalizeKey): array
    {
        $map = [];
        $rows = DB::table($table)->where('tenant_id', $this->tenant->id)->whereIn('kind', ['alias', 'code_change'])
            ->get(['id', $targetColumn, 'data_source_id', 'source_code', 'valid_from', 'valid_to']);
        foreach ($rows as $row) {
            $key = $normalizeKey ? self::norm((string) $row->source_code) : (string) $row->source_code;
            $map[$key][] = [
                'id' => (int) $row->id, 'target' => (int) $row->{$targetColumn},
                'source' => $row->data_source_id !== null ? (int) $row->data_source_id : null,
                'from' => $row->valid_from !== null ? substr((string) $row->valid_from, 0, 10) : null,
                'to' => $row->valid_to !== null ? substr((string) $row->valid_to, 0, 10) : null,
            ];
        }

        return $map;
    }

    /**
     * El alias que rige en `$on`: de la fuente de la resolución antes que el general, y entre iguales el de vigencia más reciente.
     * Un alias de OTRA fuente nunca aplica; con vigencia que no cubre la fecha, tampoco.
     *
     * @param  list<array{id: int, target: int, source: ?int, from: ?string, to: ?string}>  $candidates
     */
    private function matchAlias(array $candidates, ?string $on): ?int
    {
        if ($candidates === []) {
            return null;
        }
        $day = $on !== null ? substr($on, 0, 10) : $this->today;
        $best = null;
        $bestRank = null;
        foreach ($candidates as $alias) {
            if ($alias['source'] !== null && $alias['source'] !== $this->sourceId) {
                continue;
            }
            if (($alias['from'] !== null && $alias['from'] > $day) || ($alias['to'] !== null && $alias['to'] < $day)) {
                continue;
            }
            $rank = [$alias['source'] !== null ? 1 : 0, $alias['from'] ?? '', $alias['id']];
            if ($bestRank === null || $rank > $bestRank) {
                $best = $alias['target'];
                $bestRank = $rank;
            }
        }

        return $best;
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
