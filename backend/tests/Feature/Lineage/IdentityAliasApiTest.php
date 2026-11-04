<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\DataSource;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Privacy\CustomerIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Alias de identidad y cambio de código (ADR-V25-24): alta, baja y consulta de alias de producto y de cliente, cambio de código que
 * conserva la identidad canónica, vigencias, conflictos, tipos DISEÑADOS (merge/split), minimización de datos del cliente,
 * habilidades y aislamiento entre empresas.
 */
final class IdentityAliasApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /** Ejecuta una consulta de modelos con la empresa de la prueba en contexto (las peticiones HTTP lo limpian al terminar). */
    private function in(callable $callback, ?Tenant $tenant = null): mixed
    {
        return $this->withinTenant($tenant ?? $this->tenant, $callback);
    }

    private function product(string $sku, ?Tenant $tenant = null): Product
    {
        return $this->withinTenant($tenant ?? $this->tenant, fn () => Product::query()->create(['sku' => $sku, 'name' => "Producto {$sku}"]));
    }

    private function customer(string $code, ?Tenant $tenant = null): Customer
    {
        $identity = CustomerIdentity::fromCode($code);

        return $this->withinTenant($tenant ?? $this->tenant, fn () => Customer::query()->create([
            'external_code' => $identity['external_code'], 'customer_type' => $identity['customer_type'], 'tax_id_hash' => $identity['tax_id_hash'],
        ]));
    }

    private function source(?Tenant $tenant = null): DataSource
    {
        return $this->withinTenant($tenant ?? $this->tenant, fn () => DataSource::query()->create(['type' => 'file', 'name' => 'ERP']));
    }

    public function test_product_alias_crud_with_validity_and_audit(): void
    {
        $product = $this->product('OLD-1');
        $source = $this->source();

        $created = $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", [
            'source_code' => ' ABC-9 ', 'data_source_id' => $source->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30', 'notes' => 'código del ERP anterior',
        ])->assertCreated()->json('data');
        $this->assertSame('ABC-9', $created['source_code']);
        $this->assertSame('alias', $created['kind']);
        $this->assertSame([$source->id, '2026-01-01', '2026-06-30'], [$created['data_source_id'], $created['valid_from'], $created['valid_to']]);
        $this->assertSame($this->owner->id, $created['created_by']);

        $this->getJson("/api/v1/catalog/products/{$product->id}/aliases")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $created['id']);
        $this->assertSame(1, AuditLog::query()->where('action', 'product.alias_created')->count());

        $this->deleteJson("/api/v1/catalog/products/{$product->id}/aliases/{$created['id']}")->assertNoContent();
        $this->getJson("/api/v1/catalog/products/{$product->id}/aliases")->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, AuditLog::query()->where('action', 'product.alias_removed')->count());
        $this->deleteJson("/api/v1/catalog/products/{$product->id}/aliases/{$created['id']}")->assertNotFound();
    }

    public function test_alias_conflicts_are_refused_with_stable_codes(): void
    {
        $a = $this->product('SKU-A');
        $b = $this->product('SKU-B');
        $source = $this->source();
        $url = "/api/v1/catalog/products/{$a->id}/aliases";

        $this->postJson($url, ['source_code' => 'sku-a'])->assertUnprocessable()->assertJsonPath('code', 'alias_is_own_code');
        $this->postJson($url, ['source_code' => 'sku-b'])->assertUnprocessable()->assertJsonPath('code', 'alias_code_in_use');

        $this->postJson($url, ['source_code' => 'X-1', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'])->assertCreated();
        $this->postJson($url, ['source_code' => 'x-1', 'valid_from' => '2026-06-01'])->assertUnprocessable()->assertJsonPath('code', 'alias_overlap');
        $this->postJson("/api/v1/catalog/products/{$b->id}/aliases", ['source_code' => 'X-1', 'valid_from' => '2026-12-31'])->assertUnprocessable()->assertJsonPath('code', 'alias_code_in_use');
        $this->postJson($url, ['source_code' => 'X-1', 'valid_from' => '2027-01-01'])->assertCreated();   // sin solapamiento
        $this->postJson($url, ['source_code' => 'X-1', 'data_source_id' => $source->id])->assertCreated();   // otra fuente: otro alcance

        $this->postJson($url, ['source_code' => 'Y-1', 'valid_from' => '2026-05-01', 'valid_to' => '2026-04-01'])->assertUnprocessable();
        $this->postJson($url, ['source_code' => ''])->assertUnprocessable();
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['source_code' => 'Z-1', 'valid_from' => '01/05/2026'])->assertUnprocessable();
        // Un código ya fuera de vigencia no compite con el SKU actual de otra entidad.
        $this->postJson($url, ['source_code' => 'SKU-B', 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31'])->assertCreated();
    }

    public function test_merge_and_split_are_designed_not_implemented_and_code_change_has_its_own_endpoint(): void
    {
        $product = $this->product('P-1');
        $url = "/api/v1/catalog/products/{$product->id}/aliases";

        $this->postJson($url, ['source_code' => 'M-1', 'kind' => 'merge'])->assertUnprocessable()->assertJsonPath('code', 'alias_kind_designed');
        $this->postJson($url, ['source_code' => 'S-1', 'kind' => 'split'])->assertUnprocessable()->assertJsonPath('code', 'alias_kind_designed');
        $this->postJson($url, ['source_code' => 'C-1', 'kind' => 'code_change'])->assertUnprocessable()->assertJsonPath('code', 'alias_kind_use_code_change');
        $this->postJson($url, ['source_code' => 'C-1', 'kind' => 'otro'])->assertUnprocessable();
        $this->assertSame(0, $this->in(fn () => ProductAlias::query()->count()));
    }

    public function test_a_data_source_of_another_company_cannot_be_used(): void
    {
        [$other] = $this->createTenantWithUser();
        $foreignSource = $this->source($other);
        $product = $this->product('P-1');

        $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", ['source_code' => 'X', 'data_source_id' => $foreignSource->id])->assertUnprocessable();
        $this->assertSame(0, DB::table('product_aliases')->count());
    }

    public function test_code_change_makes_the_new_code_visible_and_keeps_resolving_the_old_one_to_the_same_product(): void
    {
        $product = $this->product('OLD-1');

        $response = $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", [
            'old_code' => 'old-1', 'new_code' => 'NEW-1', 'effective_from' => '2026-05-01',
        ])->assertOk();

        $this->assertSame('NEW-1', $response->json('data.sku'));
        $this->assertSame('OLD-1', $response->json('data.previous_sku'));
        $this->assertSame('code_change', $response->json('data.alias.kind'));
        $this->assertSame('OLD-1', $response->json('data.alias.source_code'));
        $this->assertSame($product->id, $response->json('data.alias.product_id'));
        $this->assertStringContainsString('2026-05-01', (string) $response->json('data.alias.notes'));
        $this->assertSame('NEW-1', $this->in(fn () => $product->refresh()->sku));
        $this->assertSame(1, $this->in(fn () => Product::query()->count()), 'la identidad canónica no cambia: no se crea otro producto');

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.sku', 'NEW-1');
        $audit = AuditLog::query()->where('action', 'product.code_changed')->sole();
        $this->assertSame('2026-05-01', $audit->metadata['effective_from']);
        $this->assertSame('OLD-1', $audit->metadata['old_code']);
    }

    public function test_code_change_validates_codes_and_dates(): void
    {
        $a = $this->product('SKU-A');
        $this->product('SKU-B');
        $url = "/api/v1/catalog/products/{$a->id}/code-change";

        $this->postJson($url, ['old_code' => 'OTRO', 'new_code' => 'NEW', 'effective_from' => '2026-05-01'])->assertUnprocessable()->assertJsonPath('code', 'old_code_mismatch');
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'sku-a', 'effective_from' => '2026-05-01'])->assertUnprocessable()->assertJsonPath('code', 'code_change_same');
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'sku-b', 'effective_from' => '2026-05-01'])->assertUnprocessable()->assertJsonPath('code', 'alias_code_in_use');
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'NEW', 'effective_from' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'NEW', 'effective_from' => 'mañana'])->assertUnprocessable();
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'NEW'])->assertUnprocessable();
        $this->assertSame('SKU-A', $this->in(fn () => $a->refresh()->sku), 'ningún intento inválido cambió el SKU');
        $this->assertSame(0, $this->in(fn () => ProductAlias::query()->count()));

        // Cadena de cambios: A → C → D; los dos códigos anteriores siguen apuntando al mismo producto.
        $this->postJson($url, ['old_code' => 'SKU-A', 'new_code' => 'SKU-C', 'effective_from' => '2026-02-01'])->assertOk();
        $this->postJson($url, ['old_code' => 'SKU-C', 'new_code' => 'SKU-D', 'effective_from' => '2026-03-01'])->assertOk();
        $this->assertEqualsCanonicalizing(['SKU-A', 'SKU-C'], $this->in(fn () => ProductAlias::query()->where('product_id', $a->id)->pluck('source_code')->all()));
        // Un código de otro producto que sigue siendo alias vigente no se puede usar como SKU nuevo.
        $b = $this->in(fn () => Product::query()->where('sku', 'SKU-B')->sole());
        $this->postJson("/api/v1/catalog/products/{$b->id}/code-change", ['old_code' => 'SKU-B', 'new_code' => 'SKU-A', 'effective_from' => '2026-04-01'])
            ->assertUnprocessable()->assertJsonPath('code', 'alias_code_in_use');
    }

    public function test_code_change_converts_an_existing_open_alias_of_the_old_code(): void
    {
        $product = $this->product('OLD-9');
        $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", ['source_code' => 'OLD-9X'])->assertCreated();
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'OLD-9', 'new_code' => 'OLD-9X', 'effective_from' => '2026-01-01'])->assertOk()
            ->assertJsonPath('data.sku', 'OLD-9X');
        $this->assertSame(['OLD-9'], $this->in(fn () => ProductAlias::query()->where('kind', 'code_change')->pluck('source_code')->all()));
    }

    public function test_abilities_viewers_read_analysts_write(): void
    {
        $product = $this->product('P-1');
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $analyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);
        $alias = $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", ['source_code' => 'A-1'])->assertCreated()->json('data.id');

        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->getJson("/api/v1/catalog/products/{$product->id}/aliases")->assertOk();
        $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", ['source_code' => 'A-2'])->assertForbidden();
        $this->deleteJson("/api/v1/catalog/products/{$product->id}/aliases/{$alias}")->assertForbidden();
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'P-1', 'new_code' => 'P-2', 'effective_from' => '2026-01-01'])->assertForbidden();

        $this->actingAsTenantUser($analyst, $this->tenant);
        $this->postJson("/api/v1/catalog/products/{$product->id}/aliases", ['source_code' => 'A-2'])->assertCreated();
        $this->assertSame(2, $this->in(fn () => ProductAlias::query()->count()));
    }

    public function test_aliases_are_isolated_between_companies(): void
    {
        $mine = $this->product('MIO');
        $alias = $this->postJson("/api/v1/catalog/products/{$mine->id}/aliases", ['source_code' => 'COMPARTIDO'])->assertCreated()->json('data.id');

        [$b, $userB] = $this->createTenantWithUser();
        $theirs = $this->product('MIO', $b);
        $this->actingAsTenantUser($userB, $b);

        // B no ve ni toca el producto ni los alias de A (404, sin revelar existencia).
        $this->getJson("/api/v1/catalog/products/{$mine->id}/aliases")->assertNotFound();
        $this->postJson("/api/v1/catalog/products/{$mine->id}/aliases", ['source_code' => 'HACK'])->assertNotFound();
        $this->postJson("/api/v1/catalog/products/{$mine->id}/code-change", ['old_code' => 'MIO', 'new_code' => 'HACK', 'effective_from' => '2026-01-01'])->assertNotFound();
        $this->deleteJson("/api/v1/catalog/products/{$theirs->id}/aliases/{$alias}")->assertNotFound();
        $this->assertSame(1, DB::table('product_aliases')->count());
        $this->assertSame('MIO', $this->in(fn () => $mine->refresh()->sku));

        // El mismo código es libre en B: el alias de A no ocupa el espacio de B.
        $this->postJson("/api/v1/catalog/products/{$theirs->id}/aliases", ['source_code' => 'COMPARTIDO'])->assertCreated();
        $this->assertSame(2, DB::table('product_aliases')->where('source_code', 'COMPARTIDO')->count());
        $this->assertSame(1, $this->in(fn () => ProductAlias::query()->count()), 'el modelo solo ve los de la empresa activa');
    }

    public function test_customer_aliases_store_only_the_minimized_code_and_reject_generic_customers(): void
    {
        $person = $this->customer('1710034065');
        $this->assertStringStartsWith('id:', $person->external_code);

        $created = $this->postJson("/api/v1/catalog/customers/{$person->id}/aliases", ['source_code' => '1712345678', 'notes' => 'cédula en el ERP anterior'])->assertCreated()->json('data');

        $minimized = CustomerIdentity::fromCode('1712345678')['external_code'];
        $this->assertSame($minimized, $created['source_code']);
        $this->assertSame($minimized, DB::table('customer_aliases')->value('source_code'));
        $this->assertStringNotContainsString('1712345678', json_encode($created));
        $this->assertStringNotContainsString('1712345678', json_encode(DB::table('customer_aliases')->get()));
        $this->assertStringNotContainsString('1712345678', json_encode(AuditLog::query()->get()->toArray()), 'la bitácora tampoco guarda la identificación');
        $this->getJson("/api/v1/catalog/customers/{$person->id}/aliases")->assertOk()->assertJsonPath('data.0.id', $created['id']);

        // El consumidor final sí puede ser DESTINO de un alias (p. ej. el código «CF» que usa un ERP): se trata como comprador genérico.
        $final = $this->customer('9999999999999');
        $this->postJson("/api/v1/catalog/customers/{$final->id}/aliases", ['source_code' => 'CF'])->assertCreated();
    }

    public function test_generic_customer_codes_cannot_be_aliased(): void
    {
        $person = $this->customer('CLI-001');
        $this->postJson("/api/v1/catalog/customers/{$person->id}/aliases", ['source_code' => '9999999999999'])->assertUnprocessable()->assertJsonPath('code', 'alias_generic_customer');
        $this->postJson("/api/v1/catalog/customers/{$person->id}/aliases", ['source_code' => 'CLI-001'])->assertUnprocessable()->assertJsonPath('code', 'alias_is_own_code');
        $other = $this->customer('CLI-002');
        $this->postJson("/api/v1/catalog/customers/{$person->id}/aliases", ['source_code' => 'CLI-002'])->assertUnprocessable()->assertJsonPath('code', 'alias_code_in_use');
        $this->postJson("/api/v1/catalog/customers/{$person->id}/aliases", ['source_code' => 'CLI-009', 'kind' => 'merge'])->assertUnprocessable()->assertJsonPath('code', 'alias_kind_designed');
        $this->assertSame(0, $this->in(fn () => CustomerAlias::query()->count()));
        $this->assertNotNull($other);
    }

    public function test_customer_code_change_keeps_the_identity_and_never_returns_the_raw_identification(): void
    {
        $customer = $this->customer('CLI-001');

        $response = $this->postJson("/api/v1/catalog/customers/{$customer->id}/code-change", [
            'old_code' => 'CLI-001', 'new_code' => '1710034065', 'effective_from' => '2026-03-01',
        ])->assertOk();

        $minimized = CustomerIdentity::fromCode('1710034065')['external_code'];
        $this->assertSame($minimized, $response->json('data.external_code'));
        $this->assertSame('CLI-001', $response->json('data.alias.source_code'));
        $this->assertStringNotContainsString('1710034065', $response->getContent());
        $this->in(fn () => $customer->refresh());
        $this->assertSame($minimized, $customer->external_code);
        $this->assertNotNull(DB::table('customers')->where('id', $customer->id)->value('tax_id_hash'), 'el nuevo código es una cédula: queda su huella');
        $this->assertSame(1, $this->in(fn () => Customer::query()->count()));
        $this->assertStringNotContainsString('1710034065', json_encode(AuditLog::query()->get()->toArray()));

        $this->postJson("/api/v1/catalog/customers/{$customer->id}/code-change", ['old_code' => 'CLI-001', 'new_code' => 'CLI-7', 'effective_from' => '2026-03-01'])
            ->assertUnprocessable()->assertJsonPath('code', 'old_code_mismatch');
    }

    public function test_customer_aliases_are_isolated_between_companies(): void
    {
        $customer = $this->customer('CLI-001');
        $this->postJson("/api/v1/catalog/customers/{$customer->id}/aliases", ['source_code' => 'ALT-1'])->assertCreated();
        [$b, $userB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($userB, $b);

        $this->getJson("/api/v1/catalog/customers/{$customer->id}/aliases")->assertNotFound();
        $this->postJson("/api/v1/catalog/customers/{$customer->id}/code-change", ['old_code' => 'CLI-001', 'new_code' => 'X', 'effective_from' => '2026-01-01'])->assertNotFound();
        $this->assertSame(1, DB::table('customer_aliases')->count());
        $this->assertSame('CLI-001', $this->in(fn () => $customer->refresh()->external_code));
    }
}
