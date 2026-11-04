<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'locale'];

    /** Valores por defecto en memoria (coinciden con los defaults de la BD). */
    protected $attributes = ['locale' => 'es', 'is_superadmin' => false];

    // V2.5 · W6-A: el secreto TOTP (cifrado) y los hashes de recuperación nunca se serializan.
    protected $hidden = ['password', 'remember_token', 'mfa_secret_encrypted', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'mfa_enabled_at' => 'datetime',
            'mfa_locked_until' => 'datetime',
            'mfa_recovery_codes' => 'array',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['role', 'invited_by', 'commercial_role', 'commercial_scope'])
            ->withTimestamps();
    }

    /**
     * Pertenencia a una empresa en UNA consulta: rol de acceso y rol comercial (V2.5 · W6-A). `null` si no es miembro.
     *
     * @return object{role: string, commercial_role: ?string, commercial_scope: mixed}|null
     */
    public function membershipIn(Tenant|int $tenant): ?object
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $this->getKey())->first(['role', 'commercial_role', 'commercial_scope']);
    }

    /**
     * ¿Tiene el segundo factor ACTIVO (secreto verificado)? Falla cerrado ante un modelo cargado a medias: si la columna no se cargó, se lee de la base; nunca se
     * interpreta «no cargado» como «sin MFA».
     */
    public function hasMfaEnabled(): bool
    {
        if (! $this->exists) {
            return false;
        }
        if (! array_key_exists('mfa_enabled_at', $this->attributes) || ! array_key_exists('mfa_secret_encrypted', $this->attributes)) {
            $row = DB::table('users')->where('id', $this->getKey())->first(['mfa_enabled_at', 'mfa_secret_encrypted']);

            return $row !== null && $row->mfa_enabled_at !== null && $row->mfa_secret_encrypted !== null;
        }

        return $this->attributes['mfa_enabled_at'] !== null && $this->attributes['mfa_secret_encrypted'] !== null;
    }

    public function roleIn(Tenant|int $tenant): ?TenantRole
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
        $role = $this->tenants()->where('tenants.id', $tenantId)->value('tenant_users.role');

        return $role ? TenantRole::from($role) : null;
    }
}
