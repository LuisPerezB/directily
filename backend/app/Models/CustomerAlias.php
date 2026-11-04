<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `customer_aliases` (ADR-V25-24): código de origen de un cliente → identidad canónica, con vigencia.
 * `source_code` guarda la forma ya minimizada del código (la de `customers.external_code`): una cédula o un RUC nunca se
 * almacenan en claro, ni en el alias ni en la respuesta de la API (LOPDP). Semántica de `kind` y `data_source_id` como
 * en ProductAlias.
 */
class CustomerAlias extends Model
{
    use BelongsToTenant;

    public const KINDS = ['alias', 'code_change', 'merge', 'split'];

    public const CREATABLE_KINDS = ['alias'];

    protected $table = 'customer_aliases';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'data_source_id' => $this->data_source_id,
            'source_code' => $this->source_code,
            'kind' => $this->kind,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
