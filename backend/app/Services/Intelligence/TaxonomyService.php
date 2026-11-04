<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTaxonomyAssignment;
use App\Models\TaxonomyNode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Taxonomía básica de la empresa (V2.1 §24.2; T13; ADR-V25-25): jerarquía Sector → Industria → Categoría → Subcategoría versionada y asignación
 * producto → nodo con procedencia.
 *
 * - Jerarquía: el padre de un nodo es del nivel inmediatamente superior (la valida el modelo); los hijos de una versión antigua siguen apuntando a ella.
 * - Versionado: una nueva versión del mismo código cierra la anterior (`valid_to`) y no la modifica; las asignaciones conservan el nodo (versión) que referencian.
 * - Procedencia: `manual` (la asignó un usuario) y `verified` (propuesta confirmada por un usuario) son verdad usable. `llm_proposed` es una candidata con
 *   revisión pendiente y NUNCA se usa como verdad (`resolveForProduct` la ignora). Este paquete no llama a ningún LLM: la propuesta entra por `proposeAssignment`.
 * - El SKU canónico no se sustituye: la asignación enlaza el producto, no lo reemplaza.
 * - Un producto tiene como máximo una asignación primaria usable por fecha: una nueva cierra la abierta anterior o se rechaza si se solapan.
 */
final class TaxonomyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ── Nodos ──

    /** @param array<string, mixed> $data */
    public function createNode(array $data): TaxonomyNode
    {
        $this->assertErpCategoryVisible($data['erp_category_id'] ?? null);
        $this->assertValidity($data['valid_from'] ?? null, $data['valid_to'] ?? null);
        if (TaxonomyNode::query()->own()->where('code', $data['code'])->exists()) {
            throw new IntelligenceRuleException('Ya existe un nodo con ese código; para cambiarlo cree una nueva versión.', 'taxonomy_code_taken', 409);
        }
        $node = new TaxonomyNode;
        $node->fill($data + ['version' => 1]);
        $node->save();
        $this->audit->log('intelligence.taxonomy_node_created', $node, ['level' => $node->level, 'code' => $node->code, 'version' => $node->version]);

        return $node->refresh();
    }

    /** @param array<string, mixed> $data */
    public function updateNode(TaxonomyNode $node, array $data): TaxonomyNode
    {
        if (array_key_exists('erp_category_id', $data)) {
            $this->assertErpCategoryVisible($data['erp_category_id']);
        }
        $this->assertValidity($data['valid_from'] ?? $node->valid_from?->toDateString(), $data['valid_to'] ?? $node->valid_to?->toDateString());
        $node->fill($data)->save();
        $this->audit->log('intelligence.taxonomy_node_updated', $node, ['code' => $node->code, 'version' => $node->version, 'changed' => array_keys($data)]);

        return $node->refresh();
    }

    /**
     * Nueva versión de un nodo propio: mismo código, versión + 1; la anterior queda cerrada el día previo a `valid_from`.
     *
     * @param  array<string, mixed>  $changes  name, attributes, erp_category_id, parent_id, valid_from
     */
    public function newVersion(TaxonomyNode $node, array $changes): TaxonomyNode
    {
        return DB::transaction(function () use ($node, $changes): TaxonomyNode {
            $latest = TaxonomyNode::query()->own()->where('code', $node->code)->orderByDesc('version')->lockForUpdate()->first();
            if ($latest === null || $latest->id !== $node->id) {
                throw new IntelligenceRuleException('Solo se puede versionar la versión más reciente del nodo.', 'taxonomy_not_latest_version', 409, ['latest_version' => $latest?->version]);
            }
            $from = CarbonImmutable::parse((string) ($changes['valid_from'] ?? now()->toDateString()))->startOfDay();
            if ($node->valid_from !== null && $from->lessThanOrEqualTo($node->valid_from)) {
                throw new IntelligenceRuleException('La nueva versión debe regir después del inicio de la versión actual.', 'taxonomy_invalid_validity', 422);
            }
            $this->assertErpCategoryVisible($changes['erp_category_id'] ?? null);
            $new = new TaxonomyNode;
            $new->fill([
                'parent_id' => array_key_exists('parent_id', $changes) ? $changes['parent_id'] : $node->parent_id,
                'level' => $node->level,
                'code' => $node->code,
                'name' => $changes['name'] ?? $node->name,
                'version' => $node->version + 1,
                'valid_from' => $from->toDateString(),
                'valid_to' => null,
                'erp_category_id' => array_key_exists('erp_category_id', $changes) ? $changes['erp_category_id'] : $node->erp_category_id,
                'attributes' => $changes['attributes'] ?? $node->getAttribute('attributes'),
            ]);
            $new->save();
            if ($node->valid_to === null || $node->valid_to->greaterThanOrEqualTo($from)) {
                $node->forceFill(['valid_to' => $from->subDay()->toDateString()])->save();
            }
            $this->audit->log('intelligence.taxonomy_node_versioned', $new, ['code' => $new->code, 'from_version' => $node->version, 'to_version' => $new->version]);

            return $new->refresh();
        });
    }

    public function deleteNode(TaxonomyNode $node): void
    {
        if (TaxonomyNode::query()->where('parent_id', $node->id)->exists()) {
            throw new IntelligenceRuleException('El nodo tiene nodos hijos; elimínelos o muévalos primero.', 'taxonomy_node_has_children', 409);
        }
        if (ProductTaxonomyAssignment::query()->where('taxonomy_node_id', $node->id)->exists()) {
            throw new IntelligenceRuleException('El nodo tiene productos asignados; ciérrelos primero (el historial se conserva).', 'taxonomy_node_in_use', 409);
        }
        $this->audit->log('intelligence.taxonomy_node_deleted', $node, ['code' => $node->code, 'version' => $node->version]);
        $node->delete();
    }

    // ── Asignaciones producto → nodo ──

    /**
     * Asignación MANUAL: la hace un usuario y cuenta como verdad desde ya.
     *
     * @param  array<string, mixed>  $data  product_id, taxonomy_node_id, confidence?, is_primary?, valid_from?, valid_to?
     */
    public function assign(User $user, array $data): ProductTaxonomyAssignment
    {
        return $this->create($user, $data, ProductTaxonomyAssignment::PROVENANCE_MANUAL);
    }

    /**
     * PROPUESTA de clasificación (p. ej. de un LLM): queda como candidata con procedencia `llm_proposed`, sin valor de verdad hasta que un usuario la verifique.
     *
     * @param  array<string, mixed>  $data
     */
    public function proposeAssignment(?User $proposedBy, array $data): ProductTaxonomyAssignment
    {
        return $this->create($proposedBy, $data, ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED);
    }

    /** La verificación humana convierte la propuesta en verdad (`verified`). Cierra la primaria abierta anterior si corresponde. */
    public function verify(User $reviewer, ProductTaxonomyAssignment $assignment): ProductTaxonomyAssignment
    {
        if ($assignment->provenance !== ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED) {
            throw new IntelligenceRuleException('Solo una propuesta pendiente de revisión se puede verificar.', 'assignment_not_proposed', 409, ['provenance' => $assignment->provenance]);
        }

        return DB::transaction(function () use ($reviewer, $assignment): ProductTaxonomyAssignment {
            if ($assignment->is_primary) {
                $this->closeOrRejectOverlap($assignment->product_id, $assignment->valid_from, $assignment->valid_to, $assignment->id);
            }
            $assignment->forceFill(['provenance' => ProductTaxonomyAssignment::PROVENANCE_VERIFIED, 'verified_by' => $reviewer->id, 'verified_at' => now()])->save();
            $this->audit->log('intelligence.taxonomy_assignment_verified', $assignment, ['product_id' => $assignment->product_id, 'taxonomy_node_id' => $assignment->taxonomy_node_id]);

            return $assignment->refresh();
        });
    }

    /** Rechazar una propuesta la elimina (la evidencia queda en la auditoría); una asignación usable se cierra con `valid_to`, no se borra. */
    public function rejectProposal(ProductTaxonomyAssignment $assignment): void
    {
        if ($assignment->provenance !== ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED) {
            throw new IntelligenceRuleException('Solo se descarta una propuesta pendiente; una asignación vigente se cierra con valid_to para conservar el historial.', 'assignment_not_proposed', 409, ['provenance' => $assignment->provenance]);
        }
        $this->audit->log('intelligence.taxonomy_assignment_rejected', $assignment, ['product_id' => $assignment->product_id, 'taxonomy_node_id' => $assignment->taxonomy_node_id]);
        $assignment->delete();
    }

    /**
     * Ajusta la vigencia (cierre) o la confianza de una asignación existente.
     *
     * @param  array<string, mixed>  $data  valid_from?, valid_to?, confidence?
     */
    public function updateAssignment(ProductTaxonomyAssignment $assignment, array $data): ProductTaxonomyAssignment
    {
        $this->assertValidity($data['valid_from'] ?? $assignment->valid_from?->toDateString(), $data['valid_to'] ?? $assignment->valid_to?->toDateString());

        return DB::transaction(function () use ($assignment, $data): ProductTaxonomyAssignment {
            $assignment->fill($data);
            if ($assignment->isUsable() && $assignment->is_primary && ($assignment->isDirty('valid_from') || $assignment->isDirty('valid_to'))) {
                $this->closeOrRejectOverlap($assignment->product_id, $assignment->valid_from, $assignment->valid_to, $assignment->id);
            }
            $assignment->save();
            $this->audit->log('intelligence.taxonomy_assignment_updated', $assignment, ['changed' => array_keys($data)]);

            return $assignment->refresh();
        });
    }

    /** El nodo usable (manual o verificado) y primario de un producto en una fecha. Una propuesta del LLM NO cuenta, aunque sea la única. */
    public function resolveForProduct(int $productId, ?CarbonInterface $asOf = null): ?TaxonomyNode
    {
        $asOf ??= CarbonImmutable::now();
        $assignment = ProductTaxonomyAssignment::query()->with('node')
            ->where('product_id', $productId)->where('is_primary', true)->usable()->validOn($asOf)
            ->orderByDesc('valid_from')->orderByDesc('id')->first();

        return $assignment?->node;
    }

    /** @return Collection<int, ProductTaxonomyAssignment> propuestas pendientes de revisión */
    public function pendingReview(): Collection
    {
        return ProductTaxonomyAssignment::query()->with('node')->where('provenance', ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function presentNode(TaxonomyNode $n): array
    {
        return [
            'id' => $n->id, 'scope' => $n->isPublic() ? 'public' : 'tenant', 'parent_id' => $n->parent_id, 'level' => $n->level, 'code' => $n->code, 'name' => $n->name,
            'version' => $n->version, 'valid_from' => $n->valid_from?->toDateString(), 'valid_to' => $n->valid_to?->toDateString(),
            'erp_category_id' => $n->erp_category_id, 'attributes' => (object) ($n->getAttribute('attributes') ?? []), 'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentAssignment(ProductTaxonomyAssignment $a): array
    {
        return [
            'id' => $a->id, 'product_id' => $a->product_id, 'taxonomy_node_id' => $a->taxonomy_node_id,
            'node' => $a->relationLoaded('node') && $a->node !== null ? ['id' => $a->node->id, 'level' => $a->node->level, 'code' => $a->node->code, 'name' => $a->node->name, 'version' => $a->node->version] : null,
            'provenance' => $a->provenance,
            // Una propuesta del LLM está pendiente de revisión y no cuenta como verdad.
            'usable' => $a->isUsable(),
            'pending_review' => $a->provenance === ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED,
            'confidence' => $a->confidence, 'is_primary' => $a->is_primary,
            'proposed_by' => $a->proposed_by, 'verified_by' => $a->verified_by, 'verified_at' => $a->verified_at?->toIso8601String(),
            'valid_from' => $a->valid_from?->toDateString(), 'valid_to' => $a->valid_to?->toDateString(), 'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    // ── Internos ──

    /** @param array<string, mixed> $data */
    private function create(?User $user, array $data, string $provenance): ProductTaxonomyAssignment
    {
        if (! Product::query()->whereKey($data['product_id'])->exists()) {
            throw new IntelligenceRuleException('El producto no existe en la empresa.', 'product_not_found', 422);
        }
        if (! TaxonomyNode::query()->whereKey($data['taxonomy_node_id'])->exists()) {
            throw new IntelligenceRuleException('El nodo de taxonomía no existe o no es visible para la empresa.', 'taxonomy_node_not_found', 422);
        }
        $this->assertValidity($data['valid_from'] ?? null, $data['valid_to'] ?? null);
        $primary = (bool) ($data['is_primary'] ?? true);

        return DB::transaction(function () use ($user, $data, $provenance, $primary): ProductTaxonomyAssignment {
            if ($primary && in_array($provenance, ProductTaxonomyAssignment::USABLE, true)) {
                $this->closeOrRejectOverlap(
                    (int) $data['product_id'],
                    isset($data['valid_from']) ? CarbonImmutable::parse((string) $data['valid_from']) : null,
                    isset($data['valid_to']) ? CarbonImmutable::parse((string) $data['valid_to']) : null,
                    null,
                );
            }
            $assignment = new ProductTaxonomyAssignment;
            $assignment->fill([
                'product_id' => (int) $data['product_id'], 'taxonomy_node_id' => (int) $data['taxonomy_node_id'], 'provenance' => $provenance,
                'confidence' => $data['confidence'] ?? null, 'is_primary' => $primary, 'valid_from' => $data['valid_from'] ?? null, 'valid_to' => $data['valid_to'] ?? null,
                'proposed_by' => $provenance === ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED ? $user?->id : null,
            ]);
            $assignment->save();
            $this->audit->log('intelligence.taxonomy_assignment_created', $assignment, ['product_id' => $assignment->product_id, 'taxonomy_node_id' => $assignment->taxonomy_node_id, 'provenance' => $provenance]);

            return $assignment->refresh();
        });
    }

    /**
     * Garantiza una sola primaria usable por fecha: si la abierta anterior (sin valid_to) empieza antes que la nueva, se cierra el día previo;
     * cualquier otro solapamiento se rechaza.
     */
    private function closeOrRejectOverlap(int $productId, ?CarbonInterface $from, ?CarbonInterface $to, ?int $ignoreId): void
    {
        $existing = ProductTaxonomyAssignment::query()->where('product_id', $productId)->where('is_primary', true)->usable()
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->lockForUpdate()->get();
        foreach ($existing as $other) {
            $otherFrom = $other->valid_from;
            $otherTo = $other->valid_to;
            $overlaps = ($to === null || $otherFrom === null || $otherFrom->lessThanOrEqualTo($to)) && ($from === null || $otherTo === null || $otherTo->greaterThanOrEqualTo($from));
            if (! $overlaps) {
                continue;
            }
            $canClose = $otherTo === null && $from !== null && ($otherFrom === null || $otherFrom->lessThan($from));
            if (! $canClose) {
                throw new IntelligenceRuleException('El producto ya tiene una asignación primaria vigente que se solapa con esta; ciérrela primero o indique una vigencia posterior.', 'overlapping_primary_assignment', 409, ['assignment_id' => $other->id]);
            }
            $other->forceFill(['valid_to' => $from->subDay()->toDateString()])->save();
        }
    }

    private function assertErpCategoryVisible(mixed $categoryId): void
    {
        if ($categoryId !== null && ! ProductCategory::query()->whereKey($categoryId)->exists()) {
            throw new IntelligenceRuleException('La categoría del ERP no existe en la empresa.', 'erp_category_not_found', 422);
        }
    }

    private function assertValidity(mixed $from, mixed $to): void
    {
        if ($from !== null && $to !== null && CarbonImmutable::parse((string) $to)->lessThan(CarbonImmutable::parse((string) $from))) {
            throw new IntelligenceRuleException('La vigencia es inválida: valid_to es anterior a valid_from.', 'taxonomy_invalid_validity', 422);
        }
    }
}
