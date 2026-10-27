<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Empresa cliente de Predictily. Raíz del aislamiento multi-tenant.
 * Este modelo NO usa BelongsToTenant (es el propio tenant).
 */
class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'tax_id', 'sector', 'country', 'currency', 'timezone', 'locale',
        'plan_id', 'plan_overrides', 'settings', 'data_retention_months', 'status',
    ];

    /** Valores por defecto de settings; se combinan con los guardados. */
    public const DEFAULT_SETTINGS = [
        'default_horizon_days' => 30,
        'service_level' => 0.95,
        'review_period_days' => 7,
        'default_lead_time_days' => 7,
        'forecast_schedule' => 'weekly',
        'stockout_risk_threshold' => 0.3,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'plan_overrides' => 'array',
            'allow_shared_training' => 'boolean',
            'shared_training_consented_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->uuid ??= (string) Str::uuid();
            $tenant->settings ??= self::DEFAULT_SETTINGS;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, mixed> */
    public function effectiveSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['role', 'invited_by'])
            ->withTimestamps();
    }

    public function roleOf(User $user): ?TenantRole
    {
        $role = $this->users()->whereKey($user->getKey())->value('tenant_users.role');

        return $role ? TenantRole::from($role) : null;
    }
}
