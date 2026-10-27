<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token de API. Cuando tenant_id no es NULL, el token solo opera sobre esa empresa
 * (conector REST) y el header X-Tenant-ID se ignora.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'tenant_id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
