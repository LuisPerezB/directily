<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bitácora de accesos a una concesión (solo inserción): `read` o un rechazo `denied_expired | denied_revoked | denied_scope`. Cada intento del
 * receptor y de terceros queda registrado. Una empresa ve los accesos que ELLA hizo y los hechos sobre las concesiones que ELLA emitió.
 */
class SharedForecastGrantAccess extends Model
{
    public const ACTION_READ = 'read';

    public const ACTION_DENIED_EXPIRED = 'denied_expired';

    public const ACTION_DENIED_REVOKED = 'denied_revoked';

    public const ACTION_DENIED_SCOPE = 'denied_scope';

    public const ACTIONS = [self::ACTION_READ, self::ACTION_DENIED_EXPIRED, self::ACTION_DENIED_REVOKED, self::ACTION_DENIED_SCOPE];

    protected $table = 'shared_forecast_grant_accesses';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('access_party', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException('Consulta a shared_forecast_grant_accesses sin empresa activa.');
            }
            $table = $builder->getModel()->getTable();
            $builder->where(fn (Builder $q) => $q->where("{$table}.accessed_by_tenant_id", $context->id())
                ->orWhereIn("{$table}.grant_id", SharedForecastGrant::withoutParties()->where('issuer_tenant_id', $context->id())->select('id')));
        });
        // Bitácora inmutable: ni se corrige ni se borra desde la aplicación.
        static::updating(fn () => throw new LogicException('La bitácora de accesos es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('La bitácora de accesos es de solo inserción.'));
    }

    /** Bitácora de solo inserción también en masa: el builder rechaza update, delete, upsert y truncate (ver AppendOnlyBuilder). */
    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'grant_id' => 'integer',
            'accessed_by_tenant_id' => 'integer',
            'accessed_at' => 'datetime',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(SharedForecastGrant::class, 'grant_id');
    }
}
