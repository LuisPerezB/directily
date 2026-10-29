<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Observers\PromotionObserver;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabla `promotions`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-core.
 *
 * `known_at` = cuándo el negocio conocía la promoción (por defecto, su alta). Cada alta,
 * cambio de un campo versionado, baja (lógica) o restauración agrega una fila a
 * `promotion_revisions` (PromotionObserver): el motor pronostica cada corte solo con lo que
 * se conocía ese día.
 */
#[ObservedBy([PromotionObserver::class])]
class Promotion extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    /** Origen que se anotará en la próxima revisión (api, import). No es una columna. */
    public string $revisionSource = 'api';

    protected function casts(): array
    {
        return [
            'discount_pct' => 'float',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'known_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Interpreta una fecha o fecha-hora de disponibilidad: sin zona explícita se asume la de la
     * empresa ("2026-03-10" = medianoche local) y se guarda en UTC (zona de la aplicación).
     */
    public static function parseKnownAt(DateTimeInterface|string $value, string $timezone): CarbonImmutable
    {
        $at = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, $timezone);

        return $at->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PromotionRevision::class, 'promotion_id')->orderBy('revision');
    }
}
