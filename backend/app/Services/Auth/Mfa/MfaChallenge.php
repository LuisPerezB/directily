<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Desafío que sigue a una contraseña correcta cuando la persona tiene MFA (V2.5 · W6-A): un valor aleatorio opaco de un solo uso y corta vida que ata el segundo paso
 * al primero. Se guarda solo su SHA-256 en la caché (con la persona y si pidió «recordarme»); el inicio de sesión (sesión o token) NO se emite hasta verificar el código.
 */
final class MfaChallenge
{
    public function ttl(): int
    {
        return max(30, (int) config('security.mfa.challenge_ttl_seconds', 300));
    }

    public function issue(int $userId, bool $remember): string
    {
        $token = Str::random(48);
        Cache::put($this->key($token), ['user_id' => $userId, 'remember' => $remember], $this->ttl());

        return $token;
    }

    /** @return array{user_id: int, remember: bool}|null */
    public function peek(string $token): ?array
    {
        $value = Cache::get($this->key($token));

        return is_array($value) && isset($value['user_id']) ? ['user_id' => (int) $value['user_id'], 'remember' => (bool) ($value['remember'] ?? false)] : null;
    }

    /**
     * Candado de corta vida sobre UN desafío: quien lo toma verifica el código y consume el desafío sin que otra petición paralela con el mismo desafío haga lo mismo (sin él, dos
     * peticiones simultáneas con el mismo desafío y un código válido podrían emitir dos sesiones). Devuelve null si otra petición ya lo tiene.
     */
    public function lock(string $token): ?Lock
    {
        $lock = Cache::lock('mfa:challenge-lock:'.hash('sha256', $token), 15);

        return $lock->get() ? $lock : null;
    }

    /** Un desafío se consume una sola vez (un código válido no se reutiliza con el mismo desafío). */
    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'mfa:challenge:'.hash('sha256', $token);
    }
}
