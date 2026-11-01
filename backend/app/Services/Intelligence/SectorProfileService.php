<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TenantSectorProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Illuminate\Support\Collection;

/**
 * Perfiles de relevancia sectorial de la empresa (V2.1 §24.2/§24.11). Una empresa puede tener varios perfiles activos a la vez, cada uno con prioridad
 * (menor = mayor prioridad) y alcance (empresa, sucursal, categoría o producto). El alcance más específico gana sobre el general; a igual alcance,
 * la prioridad numérica menor.
 *
 * `profile_key` es el código del perfil registrado en el motor (engine/predictily_engine/platform/extensions.py). Aquí solo se valida su formato:
 * contrastarlo con el registro del motor es DISEÑADO (si el motor no conoce la clave, el perfil no aporta nada y se muestra como tal).
 */
final class SectorProfileService
{
    /** Especificidad del alcance: mayor = más específico. */
    private const SPECIFICITY = ['tenant' => 0, 'branch' => 1, 'category' => 2, 'product' => 3];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): TenantSectorProfile
    {
        $scope = (string) ($data['scope'] ?? 'tenant');
        $ref = $this->normalizeRef($scope, $data['scope_ref'] ?? null);
        $this->assertNoDuplicate((string) $data['profile_key'], $scope, $ref, null);
        $profile = new TenantSectorProfile;
        $profile->fill([
            'profile_key' => $data['profile_key'], 'priority' => $data['priority'] ?? 100, 'scope' => $scope, 'scope_ref' => $ref, 'is_active' => $data['is_active'] ?? true,
        ]);
        $profile->save();
        $this->audit->log('intelligence.sector_profile_created', $profile, ['profile_key' => $profile->profile_key, 'scope' => $scope, 'priority' => $profile->priority]);

        return $profile->refresh();
    }

    /** @param array<string, mixed> $data priority?, is_active? (la clave y el alcance no cambian: se crea otro perfil) */
    public function update(TenantSectorProfile $profile, array $data): TenantSectorProfile
    {
        $profile->fill(array_intersect_key($data, array_flip(['priority', 'is_active'])))->save();
        $this->audit->log('intelligence.sector_profile_updated', $profile, ['profile_key' => $profile->profile_key, 'changed' => array_keys($data)]);

        return $profile->refresh();
    }

    public function delete(TenantSectorProfile $profile): void
    {
        $this->audit->log('intelligence.sector_profile_deleted', $profile, ['profile_key' => $profile->profile_key, 'scope' => $profile->scope]);
        $profile->delete();
    }

    /**
     * Perfiles activos que aplican a un contexto, del más relevante al menos: alcance más específico primero y, a igual alcance, menor prioridad numérica.
     *
     * @return Collection<int, TenantSectorProfile>
     */
    public function applicable(?int $branchId = null, ?int $categoryId = null, ?int $productId = null): Collection
    {
        return TenantSectorProfile::query()->where('is_active', true)->get()
            ->filter(fn (TenantSectorProfile $p) => match ($p->scope) {
                'tenant' => true,
                'branch' => $branchId !== null && $p->scope_ref === (string) $branchId,
                'category' => $categoryId !== null && $p->scope_ref === (string) $categoryId,
                'product' => $productId !== null && $p->scope_ref === (string) $productId,
                default => false,
            })
            ->sortBy([fn ($a, $b) => self::SPECIFICITY[$b->scope] <=> self::SPECIFICITY[$a->scope], ['priority', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** @return array<string, mixed> */
    public function present(TenantSectorProfile $p): array
    {
        return [
            'id' => $p->id, 'profile_key' => $p->profile_key, 'priority' => $p->priority, 'scope' => $p->scope, 'scope_ref' => $p->scope_ref,
            'is_active' => $p->is_active, 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function normalizeRef(string $scope, mixed $ref): ?string
    {
        if ($scope === 'tenant') {
            if ($ref !== null && $ref !== '') {
                throw new IntelligenceRuleException('El alcance «tenant» no lleva scope_ref.', 'scope_ref_not_allowed', 422);
            }

            return null;
        }
        if ($ref === null || $ref === '' || ! ctype_digit((string) $ref)) {
            throw new IntelligenceRuleException("El alcance «{$scope}» requiere scope_ref con el identificador numérico del elemento.", 'scope_ref_required', 422);
        }
        $model = match ($scope) {
            'branch' => Branch::class,
            'category' => ProductCategory::class,
            'product' => Product::class,
        };
        if (! $model::query()->whereKey((int) $ref)->exists()) {
            throw new IntelligenceRuleException("El elemento de alcance «{$scope}» no existe en la empresa.", 'scope_ref_not_found', 422);
        }

        return (string) (int) $ref;
    }

    private function assertNoDuplicate(string $key, string $scope, ?string $ref, ?int $ignoreId): void
    {
        // El índice único no cubre scope_ref NULL (alcance de empresa): se verifica aquí.
        $exists = TenantSectorProfile::query()->where('profile_key', $key)->where('scope', $scope)
            ->when($ref === null, fn ($q) => $q->whereNull('scope_ref'), fn ($q) => $q->where('scope_ref', $ref))
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($exists) {
            throw new IntelligenceRuleException('La empresa ya tiene ese perfil con el mismo alcance.', 'sector_profile_duplicate', 409);
        }
    }
}
