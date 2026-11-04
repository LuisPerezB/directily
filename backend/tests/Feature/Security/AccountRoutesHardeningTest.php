<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Models\DataSource;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión L8 y L9): la descarga de una exportación vuelve a comprobar la membresía y la habilidad al descargar (el enlace firmado vive una hora y la ruta no pasa por
 * `tenant`); el token de conector tiene un tope de 365 días.
 */
final class AccountRoutesHardeningTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    /** Pide y obtiene una exportación; devuelve la ruta firmada (relativa) para descargarla. */
    private function exportLink(User $owner, Tenant $tenant): string
    {
        $id = $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $url = (string) $this->getJson("/api/v1/tenant/export/{$id}")->assertOk()->assertJsonPath('data.status', 'completed')->json('data.download_url');
        $this->assertNotSame('', $url);

        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_l8_the_export_download_rechecks_membership_and_ability_at_download_time(): void
    {
        Storage::fake('local');
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $second = $this->addUserToTenant($tenant, TenantRole::Owner);   // evita que «último propietario» estorbe al degradar al primero
        $link = $this->exportLink($owner, $tenant);

        // Mientras siga siendo propietario, descarga.
        Auth::forgetGuards();
        $this->actingAsTenantUser($owner, $tenant)->get($link)->assertOk();

        // Degradado a administrador (sin tenant.export_all): el MISMO enlace, aún vigente, ya no descarga.
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['role' => TenantRole::Admin->value]);
        Auth::forgetGuards();
        $this->actingAsTenantUser($owner->refresh(), $tenant)->getJson($link)->assertNotFound();
        // Quitado de la empresa: tampoco.
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->delete();
        Auth::forgetGuards();
        $this->actingAsTenantUser($owner->refresh(), $tenant)->getJson($link)->assertNotFound();
        // Y otra persona (aunque sea propietaria de la misma empresa) no usa un enlace que pidió otra.
        Auth::forgetGuards();
        $this->actingAsTenantUser($second, $tenant)->getJson($link)->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'data.exported')->count(), 'solo la descarga legítima quedó registrada');
    }

    public function test_l8_a_token_without_the_export_ability_or_bound_elsewhere_cannot_download(): void
    {
        Storage::fake('local');
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $link = $this->exportLink($owner, $tenant);
        [$other] = $this->createTenantWithUser(TenantRole::Owner);

        $readOnly = $owner->createToken('user:ro', ['data.view']);
        $readOnly->accessToken->forceFill(['tenant_id' => $tenant->id])->save();
        $elsewhere = $owner->createToken('user:otra', ['tenant.export_all']);
        $elsewhere->accessToken->forceFill(['tenant_id' => $other->id])->save();
        $allowed = $owner->createToken('user:exporta', ['tenant.export_all']);
        $allowed->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        foreach ([$readOnly, $elsewhere] as $new) {
            Auth::forgetGuards();
            $this->flushHeaders();
            $this->withToken($new->plainTextToken)->getJson($link)->assertNotFound();
        }
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withToken($allowed->plainTextToken)->get($link)->assertOk();
        $this->assertSame(3, PersonalAccessToken::query()->count());
    }

    public function test_l9_the_connector_token_lifetime_is_capped_at_365_days(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $source = $this->withinTenant($tenant, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'F', 'config' => []]));
        $this->freezeClock();
        $api = fn () => $this->actingAsTenantUser($owner, $tenant);

        $api()->postJson("/api/v1/data-sources/{$source->id}/tokens", ['expires_in_days' => 366])->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
        $api()->postJson("/api/v1/data-sources/{$source->id}/tokens", ['expires_in_days' => 730])->assertUnprocessable();
        $api()->postJson("/api/v1/data-sources/{$source->id}/tokens", ['expires_in_days' => 365])->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(365)->toIso8601String());
        // El valor por defecto configurable tampoco puede pasar de 365 días.
        config(['security.tokens.connector_default_ttl_days' => 1000]);
        $api()->postJson("/api/v1/data-sources/{$source->id}/tokens")->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(365)->toIso8601String());
        $this->assertSame(0, PersonalAccessToken::query()->whereNull('expires_at')->count());
    }
}
