<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Audit\AuditLogger;
use App\Support\Privacy\CustomerIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alta, baja y cambio de código de alias de producto y de cliente (ADR-V25-24).
 *
 * Reglas:
 * - Un alias redirige un código de origen a la identidad canónica; NO reescribe filas históricas. El motor agrupa por la
 *   identidad canónica (product_id / customer_id), que no cambia.
 * - `alias` es el único tipo que se crea por alta directa; `code_change` solo nace en un cambio de código; `merge` y `split`
 *   están DISEÑADOS y se rechazan (docs/data/BRONZE_SILVER_GOLD.md §6).
 * - Un código no puede ser alias del propio SKU ni de un código que hoy identifica a OTRA entidad, ni solaparse en vigencia con
 *   otro alias del mismo código y la misma fuente.
 * - Cliente: el código se minimiza antes de guardarse (una cédula o un RUC nunca se almacenan en claro, ni en el alias ni en la
 *   auditoría) y los clientes genéricos (consumidor final) no admiten alias.
 */
final class IdentityAliasService
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /**
     * @param  array{source_code: string, data_source_id?: ?int, kind?: string, valid_from?: ?string, valid_to?: ?string, notes?: ?string}  $input
     */
    public function add(string $type, Model $subject, array $input, ?int $userId): Model
    {
        $kind = (string) ($input['kind'] ?? 'alias');
        if (! in_array($kind, $this->aliasClass($type)::CREATABLE_KINDS, true)) {
            throw match ($kind) {
                'merge', 'split' => new AliasConflictException('alias_kind_designed', "El tipo '{$kind}' está diseñado pero no implementado: no se reescriben identidades históricas.", 'kind'),
                'code_change' => new AliasConflictException('alias_kind_use_code_change', "El tipo 'code_change' solo se crea con el cambio de código de la entidad.", 'kind'),
                default => new AliasConflictException('alias_kind_invalid', "Tipo de alias no válido: {$kind}.", 'kind'),
            };
        }
        $from = $input['valid_from'] ?? null;
        $to = $input['valid_to'] ?? null;
        if ($from !== null && $to !== null && $to < $from) {
            throw new AliasConflictException('alias_invalid_range', 'La vigencia termina antes de empezar.', 'valid_to');
        }
        $code = $this->storedCode($type, (string) $input['source_code']);
        $sourceId = isset($input['data_source_id']) ? (int) $input['data_source_id'] : null;

        return DB::transaction(function () use ($type, $subject, $input, $userId, $code, $sourceId, $from, $to): Model {
            $this->lockSubject($subject);
            $this->assertCodeFree($type, $subject, $code, $to);
            $this->assertNoOverlap($type, $code, $sourceId, $from, $to);
            $alias = $this->aliasClass($type)::query()->create([
                $this->fk($type) => $subject->getKey(),
                'data_source_id' => $sourceId,
                'source_code' => $code,
                'kind' => 'alias',
                'valid_from' => $from,
                'valid_to' => $to,
                'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 1000) : null,
                'created_by' => $userId,
            ]);
            $this->audit->log("{$type}.alias_created", $subject, array_filter([
                'alias_id' => $alias->getKey(), 'source_code' => $type === 'product' ? $code : null, 'data_source_id' => $sourceId, 'valid_from' => $from, 'valid_to' => $to,
            ], fn ($v) => $v !== null));

            return $alias->refresh();
        });
    }

    public function remove(string $type, Model $subject, Model $alias): void
    {
        DB::transaction(function () use ($type, $subject, $alias): void {
            $this->audit->log("{$type}.alias_removed", $subject, array_filter([
                'alias_id' => $alias->getKey(), 'kind' => $alias->kind, 'source_code' => $type === 'product' ? $alias->source_code : null,
            ], fn ($v) => $v !== null));
            $alias->delete();
        });
    }

    /**
     * Cambio de código de la identidad (el ERP renombró el SKU o el código del cliente): el código visible pasa a ser el
     * nuevo y el anterior sigue resolviendo a la misma identidad mediante un alias `code_change`. No se reescribe ninguna
     * fila histórica. `effective_from` queda en la nota del alias y en la auditoría.
     *
     * @return array{subject: Model, alias: Model, previous_code: string}
     */
    public function changeCode(string $type, Model $subject, string $oldCode, string $newCode, string $effectiveFrom, ?int $userId): array
    {
        if ($effectiveFrom > now()->toDateString()) {
            throw new AliasConflictException('code_change_future', 'La fecha de vigencia del cambio de código no puede ser futura.', 'effective_from');
        }

        return DB::transaction(function () use ($type, $subject, $oldCode, $newCode, $effectiveFrom, $userId): array {
            $this->lockSubject($subject);
            $old = $this->storedCode($type, $oldCode);
            $new = $this->storedCode($type, $newCode);
            $current = $type === 'product' ? (string) $subject->getAttribute('sku') : (string) $subject->getAttribute('external_code');
            if (! $this->same($type, $old, $current)) {
                throw new AliasConflictException('old_code_mismatch', 'El código anterior no coincide con el código actual de la entidad.', 'old_code');
            }
            if ($this->same($type, $old, $new)) {
                throw new AliasConflictException('code_change_same', 'El código nuevo es igual al anterior.', 'new_code');
            }
            $this->assertCodeFree($type, $subject, $new, null);

            $previous = $current;
            if ($type === 'product') {
                $subject->forceFill(['sku' => $new])->save();
            } else {
                $identity = CustomerIdentity::fromCode($newCode);
                $subject->forceFill([
                    'external_code' => $new,
                    'customer_type' => $identity['customer_type'] !== 'unknown' ? $identity['customer_type'] : $subject->getAttribute('customer_type'),
                    'tax_id_hash' => $identity['tax_id_hash'] ?? $subject->getAttribute('tax_id_hash'),
                ])->save();
            }

            $note = 'Cambio de código a '.($type === 'product' ? $new : '[código nuevo]')." vigente desde {$effectiveFrom}.";
            $existing = $this->aliasClass($type)::query()->whereNull('data_source_id')->where($this->fk($type), $subject->getKey())
                ->where(fn (Builder $q) => $this->matchCode($q, $type, $previous))->whereNull('valid_to')->first();
            if ($existing !== null) {
                $existing->forceFill(['kind' => 'code_change', 'notes' => $note])->save();
                $alias = $existing;
            } else {
                $this->assertNoOverlap($type, $previous, null, null, null);
                $alias = $this->aliasClass($type)::query()->create([
                    $this->fk($type) => $subject->getKey(), 'data_source_id' => null, 'source_code' => $previous, 'kind' => 'code_change',
                    'valid_from' => null, 'valid_to' => null, 'notes' => $note, 'created_by' => $userId,
                ]);
            }
            $this->audit->log("{$type}.code_changed", $subject, array_filter([
                'alias_id' => $alias->getKey(), 'effective_from' => $effectiveFrom,
                'old_code' => $type === 'product' ? $previous : null, 'new_code' => $type === 'product' ? $new : null,
            ], fn ($v) => $v !== null));

            return ['subject' => $subject->refresh(), 'alias' => $alias->refresh(), 'previous_code' => $type === 'product' ? $previous : '[minimizado]'];
        });
    }

    /** Código tal como se guarda: producto = SKU recortado; cliente = forma minimizada (nunca la cédula o el RUC en claro). */
    private function storedCode(string $type, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new AliasConflictException('alias_code_empty', 'El código no puede estar vacío.');
        }
        if ($type === 'product') {
            return mb_substr($raw, 0, 100);
        }
        $identity = CustomerIdentity::fromCode($raw);
        if ($identity['customer_type'] === 'final_consumer') {
            throw new AliasConflictException('alias_generic_customer', 'El consumidor final es un cliente genérico: no admite alias ni cambio de código.');
        }

        return $identity['external_code'];
    }

    private function same(string $type, string $a, string $b): bool
    {
        return $type === 'product' ? CatalogResolver::norm($a) === CatalogResolver::norm($b) : $a === $b;
    }

    private function lockSubject(Model $subject): void
    {
        $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->first();
        if ($this->context->id() !== (int) $subject->getAttribute('tenant_id')) {
            throw new InvalidArgumentException('La entidad no pertenece a la empresa activa.');
        }
    }

    /** El código no puede ser el propio ni identificar hoy a otra entidad. */
    private function assertCodeFree(string $type, Model $subject, string $code, ?string $validTo): void
    {
        $own = $type === 'product' ? (string) $subject->getAttribute('sku') : (string) $subject->getAttribute('external_code');
        if ($this->same($type, $code, $own)) {
            throw new AliasConflictException('alias_is_own_code', 'El código ya es el código actual de la entidad: no necesita alias.');
        }
        $today = now()->toDateString();
        if ($validTo !== null && $validTo < $today) {
            return;   // vigencia ya cerrada: no compite con ningún código actual
        }
        if ($type === 'product') {
            $holder = Product::withTrashed()->whereKeyNot($subject->getKey())->whereRaw('UPPER(TRIM(sku)) = ?', [CatalogResolver::norm($code)])->exists();
        } else {
            $holder = Customer::withTrashed()->whereKeyNot($subject->getKey())->where('external_code', $code)->exists();
        }
        $otherAlias = $this->aliasClass($type)::query()->where($this->fk($type), '!=', $subject->getKey())
            ->where(fn (Builder $q) => $this->matchCode($q, $type, $code))
            ->whereRaw("COALESCE(valid_to, DATE '9999-12-31') >= ?", [$today])->whereIn('kind', ['alias', 'code_change'])->exists();
        if ($holder || $otherAlias) {
            throw new AliasConflictException('alias_code_in_use', 'El código ya identifica a otra entidad de la empresa.');
        }
    }

    private function assertNoOverlap(string $type, string $code, ?int $sourceId, ?string $from, ?string $to): void
    {
        $query = $this->aliasClass($type)::query()->where(fn (Builder $q) => $this->matchCode($q, $type, $code))
            ->whereRaw("COALESCE(valid_to, DATE '9999-12-31') >= ?", [$from ?? '0001-01-01'])
            ->whereRaw("COALESCE(valid_from, DATE '0001-01-01') <= ?", [$to ?? '9999-12-31']);
        $sourceId === null ? $query->whereNull('data_source_id') : $query->where('data_source_id', $sourceId);
        if ($query->exists()) {
            throw new AliasConflictException('alias_overlap', 'Ya existe un alias de ese código para la misma fuente con una vigencia que se solapa.');
        }
    }

    /** @param  Builder<Model>  $query */
    private function matchCode(Builder $query, string $type, string $code): void
    {
        $type === 'product'
            ? $query->whereRaw('UPPER(TRIM(source_code)) = ?', [CatalogResolver::norm($code)])
            : $query->where('source_code', $code);
    }

    private function fk(string $type): string
    {
        return $type === 'product' ? 'product_id' : 'customer_id';
    }

    /** @return class-string<ProductAlias|CustomerAlias> */
    private function aliasClass(string $type): string
    {
        return $type === 'product' ? ProductAlias::class : CustomerAlias::class;
    }
}
