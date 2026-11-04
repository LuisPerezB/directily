<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revoca las SESIONES WEB (y las cookies «recordarme») de una persona (V2.5 · W6-A, revisión). Cada sesión guarda el instante de emisión (`auth_issued_at`, ms) y la persona,
 * `users.sessions_valid_after`: lo emitido en o antes de ese instante ya no vale (`AuthenticateSessionWithRevocation`). Rotar `remember_token` invalida las cookies de «recordarme». La sesión
 * ACTUAL, si la hay, se conserva (se le renueva la marca); el restablecimiento por la plataforma cierra todas.
 */
final class SessionRevoker
{
    public const ISSUED_KEY = 'auth_issued_at';

    public static function nowMs(): int
    {
        return (int) now()->getPreciseTimestamp(3);
    }

    /** Marca la sesión actual como emitida ahora (inicio de sesión de la SPA). */
    public function stamp(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::ISSUED_KEY, self::nowMs());
        }
    }

    /** Cierra las demás sesiones web y las cookies «recordarme» de la persona; la sesión de `$request`, si hay, sigue vigente. */
    public function revokeOthers(User $user, ?Request $request = null): void
    {
        $at = $this->revoke($user);
        if ($request !== null && $request->hasSession()) {
            // Estrictamente posterior al corte: las demás (emitidas en o antes de él) se rechazan; esta sigue vigente.
            $request->session()->put(self::ISSUED_KEY, $at + 1);
        }
    }

    /** Cierra TODAS las sesiones web y las cookies «recordarme» (restablecimiento por la plataforma). */
    public function revokeAll(User $user): void
    {
        $this->revoke($user);
    }

    private function revoke(User $user): int
    {
        $at = self::nowMs();
        DB::table('users')->where('id', $user->getKey())->update(['sessions_valid_after' => $at, 'remember_token' => Str::random(60)]);
        $user->refresh();

        return $at;
    }
}
