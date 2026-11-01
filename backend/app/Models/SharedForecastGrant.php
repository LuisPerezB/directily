<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\PartyOwnedBuilder;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Concesión de pronóstico entre empresas autorizadas (V2.1 §10; V2.5 §24.10; ADR-V25-25). DISEÑADA Y DESACTIVADA (config/intelligence.php).
 * El emisor concede a UN receptor el pronóstico de productos/categorías suyos, en un periodo y granularidad, con contenido acotado, vencimiento,
 * revocación y bitácora de accesos. Un pronóstico compartido NO es un pedido confirmado.
 *
 * Aislamiento fail-closed: no tiene `tenant_id`, así que su global scope exige una empresa en contexto y solo deja ver las concesiones de las que
 * esa empresa es emisora o receptora. Un tercero no ve nada. El servicio que atiende al receptor (cruce entre empresas) es el único que usa
 * `withoutParties()`, y siempre filtra por `issuer_tenant_id` explícito.
 */
class SharedForecastGrant extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const SCOPES = ['product', 'category'];

    public const GRANULARITIES = ['day', 'week', 'month'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('grant_party', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException('Consulta a shared_forecast_grants sin empresa activa.');
            }
            $table = $builder->getModel()->getTable();
            $builder->where(fn (Builder $q) => $q->where("{$table}.issuer_tenant_id", $context->id())->orWhere("{$table}.recipient_tenant_id", $context->id()));
        });
    }

    /** La lectura es de las dos partes; la escritura masiva, solo del emisor (ver PartyOwnedBuilder). */
    public function newEloquentBuilder($query): PartyOwnedBuilder
    {
        return new PartyOwnedBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'issuer_tenant_id' => 'integer',
            'recipient_tenant_id' => 'integer',
            'subjects' => 'array',
            'allowed_content' => 'array',
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** Consulta sin el filtro de partes: SOLO para el servicio de lectura del receptor, que filtra por emisor explícito y registra todo intento. */
    public static function withoutParties(): Builder
    {
        return static::withoutGlobalScope('grant_party');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'issuer_tenant_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'recipient_tenant_id');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(SharedForecastGrantAccess::class, 'grant_id');
    }

    /** Estado efectivo: una concesión activa cuyo vencimiento pasó está vencida aunque el proceso que la marca aún no haya corrido. */
    public function effectiveStatus(CarbonInterface $now): string
    {
        if ($this->status === self::STATUS_REVOKED) {
            return self::STATUS_REVOKED;
        }
        if ($this->status === self::STATUS_EXPIRED || ($this->status === self::STATUS_ACTIVE && $this->expires_at->lessThanOrEqualTo($now))) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }
}
