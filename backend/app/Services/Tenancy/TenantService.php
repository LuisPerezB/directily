<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantRole;
use App\Exceptions\DomainStateException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Ciclo de vida de una empresa: creación, configuración, consentimiento y eliminación. */
final class TenantService
{
    public const MAX_TENANTS_PER_OWNER = 10;

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @param array<string, mixed> $data */
    public function create(User $owner, array $data): Tenant
    {
        $owned = DB::table('tenant_users')->where('user_id', $owner->id)->where('role', TenantRole::Owner->value)->count();
        if ($owned >= self::MAX_TENANTS_PER_OWNER) {
            throw new DomainStateException('Alcanzó el número máximo de empresas por propietario.', 'too_many_tenants');
        }

        return DB::transaction(function () use ($owner, $data): Tenant {
            $plan = Plan::query()->where('code', config('predictily.default_plan', 'trial'))->first();
            $tenant = new Tenant;
            $tenant->fill([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'sector' => $data['sector'] ?? 'otro',
                'timezone' => $data['timezone'] ?? 'America/Guayaquil',
                'currency' => $data['currency'] ?? 'USD',
                'country' => 'EC',
                'locale' => 'es-EC',
                'plan_id' => $plan?->id,
                'settings' => array_merge(Tenant::DEFAULT_SETTINGS, $data['settings'] ?? []),
            ]);
            $tenant->save();
            $tenant->users()->attach($owner->id, ['role' => TenantRole::Owner->value]);

            $this->context->runAs($tenant, fn () => $this->audit->log('tenant.created', $tenant, ['sector' => $tenant->sector], $tenant->id, $owner->id));

            return $tenant;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Tenant $tenant, array $data): Tenant
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'legal_name', 'tax_id', 'sector', 'timezone']));
        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $attributes['settings'] = array_merge($tenant->effectiveSettings(), $data['settings']);
        }
        if (array_key_exists('data_retention_months', $data)) {
            $attributes['data_retention_months'] = (int) $data['data_retention_months'];
        }
        $tenant->fill($attributes)->save();
        $this->audit->log('tenant.updated', $tenant, ['fields' => array_keys($attributes)]);

        return $tenant;
    }

    public function completeOnboarding(Tenant $tenant): Tenant
    {
        if ($tenant->onboarding_completed_at === null) {
            $tenant->forceFill(['onboarding_completed_at' => now()])->save();
            $this->audit->log('tenant.onboarding_completed', $tenant);
        }

        return $tenant;
    }

    /** Consentimiento explícito para entrenamiento compartido (LOPDP: base jurídica requerida). */
    public function setSharedTrainingConsent(Tenant $tenant, User $user, bool $allow): Tenant
    {
        $tenant->forceFill([
            'allow_shared_training' => $allow,
            'shared_training_consented_at' => $allow ? now() : null,
            'shared_training_consented_by' => $allow ? $user->id : null,
        ])->save();
        $this->audit->log('tenant.consent_changed', $tenant, ['allow_shared_training' => $allow]);

        return $tenant;
    }

    public function requestDeletion(Tenant $tenant, string $confirmName): Tenant
    {
        if (trim($confirmName) !== $tenant->name) {
            throw new DomainStateException('El nombre de confirmación no coincide con el nombre de la empresa.', 'confirmation_mismatch');
        }
        $tenant->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()])->save();
        $this->audit->log('tenant.deletion_requested', $tenant, ['grace_days' => config('predictily.privacy.deletion_grace_days')]);

        return $tenant;
    }

    public function cancelDeletion(Tenant $tenant): Tenant
    {
        if ($tenant->status !== 'pending_deletion') {
            throw new DomainStateException('La empresa no tiene una solicitud de eliminación pendiente.', 'not_pending_deletion');
        }
        $tenant->forceFill(['status' => 'active', 'deletion_requested_at' => null])->save();
        $this->audit->log('tenant.deletion_cancelled', $tenant);

        return $tenant;
    }
}
