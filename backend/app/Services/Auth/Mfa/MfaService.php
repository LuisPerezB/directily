<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Segundo factor TOTP (V2.5 · W6-A; ADR-V25-15; RFC 6238).
 *
 * Ciclo: `begin` (secreto nuevo, cifrado con la clave de la aplicación, aún NO activo) → `confirm` (verifica el primer código, activa y entrega los códigos de recuperación UNA
 * vez) → `verify` (inicio de sesión y acciones sensibles) → `disable` (con contraseña y un código). El secreto en claro solo existe en la respuesta de `begin`; ningún
 * secreto ni código se escribe en logs ni en auditoría (la auditoría guarda hechos: «activó», «falló», «bloqueó»).
 *
 * Fuerza bruta (V2.5 · W6-A; política en config/security.php): CADA intento (código, código de recuperación o contraseña de reautenticación) reserva un lugar ANTES de comprobarse con un
 * UPDATE atómico (persona) y un contador atómico en caché (persona + IP, y IP sola). Tres niveles, de MÁS rápido a MÁS lento, para que quien conoce la contraseña no pueda bloquear a la
 * víctima desde una sola IP:
 *   1) persona + IP: 5 fallos seguidos desde la misma IP bloquean ESA combinación 15 minutos (423 `mfa_locked`); la víctima, desde su IP, sigue pudiendo entrar;
 *   2) IP: 30 fallos de cualquier persona desde una IP la bloquean 15 minutos (barrido de cuentas);
 *   3) persona (todas las IP): 15 fallos seguidos —de varias IP— bloquean a la persona 15 minutos (`users.mfa_locked_until`, persistente, atómico). Escala MÁS DESPACIO a propósito.
 * Un acierto reinicia el contador de la persona y el de persona + IP. Repetición: un paso TOTP ya aceptado no se acepta otra vez (reclamo atómico).
 */
final class MfaService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Inicia la activación: secreto nuevo (reemplaza uno pendiente). Falla si ya está activo. @return array{secret: string, otpauth_uri: string, issuer: string, digits: int, period: int, algorithm: string} */
    public function begin(User $user): array
    {
        if ($user->hasMfaEnabled()) {
            throw MfaException::alreadyEnabled();
        }
        $secret = Totp::generateSecret();
        $user->forceFill([
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_failed_attempts' => 0,
            'mfa_locked_until' => null,
            'mfa_last_used_step' => null,
        ])->save();
        $this->audit->log('mfa.enrolment_started', $user, [], null, $user->getKey());

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($this->issuer(), (string) $user->email, $secret, $this->digits(), $this->period()),
            'issuer' => $this->issuer(),
            'digits' => $this->digits(),
            'period' => $this->period(),
            'algorithm' => 'SHA1',
        ];
    }

    /**
     * Verifica el primer código y activa el segundo factor. Devuelve los códigos de recuperación en claro (única vez).
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code): array
    {
        $row = $this->row($user);
        if ($row->mfa_secret_encrypted === null || $row->mfa_enabled_at !== null) {
            throw $row->mfa_enabled_at !== null ? MfaException::alreadyEnabled() : MfaException::notPending();
        }
        $this->verifyTotp($user, $code, 'enrolment');
        $codes = RecoveryCodes::generate((int) config('security.mfa.recovery_codes', 10));
        DB::table('users')->where('id', $user->getKey())->update([
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => json_encode($codes['stored'], JSON_THROW_ON_ERROR),
        ]);
        $user->refresh();
        $this->audit->log('mfa.enabled', $user, ['codes_issued' => count($codes['plain'])], null, $user->getKey());

        return $codes['plain'];
    }

    /**
     * Verifica un código TOTP o, en su defecto, un código de recuperación. Devuelve el método usado ('totp' | 'recovery').
     *
     * @throws MfaException código inválido (422) o bloqueo (423)
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode, string $purpose = 'step_up'): string
    {
        if (! $user->hasMfaEnabled()) {
            throw MfaException::notEnabled();
        }
        if ($code !== null && $code !== '') {
            $this->verifyTotp($user, $code, $purpose);

            return 'totp';
        }
        if ($recoveryCode !== null && $recoveryCode !== '') {
            $this->verifyRecovery($user, $recoveryCode, $purpose);

            return 'recovery';
        }
        // Sin ningún dato también cuenta como intento (no es un oráculo gratuito) y no revela nada.
        $this->reserveAttempt($user, $purpose);
        $this->fail($user, $purpose);
    }

    /**
     * Reautentica con la contraseña ACTUAL antes de enrolar, regenerar códigos o cambiar el segundo factor. Cada intento (también el fallido) cuenta contra el mismo bloqueo que los
     * códigos, de modo que esta ruta no sirve para adivinar la contraseña. Un acierto NO reinicia el contador: solo un código correcto lo hace (de lo contrario, intercalar contraseñas
     * correctas permitiría esquivar el bloqueo de los códigos).
     */
    public function confirmPassword(User $user, string $password, string $purpose): void
    {
        $this->reserveAttempt($user, $purpose);
        if (! Hash::check($password, (string) $this->row($user)->password)) {
            $this->audit->log('mfa.password_failed', $user, ['purpose' => $purpose], null, $user->getKey());
            $this->failPassword($user, $purpose);
        }
    }

    /** Desactiva el segundo factor tras reautenticar con contraseña y un código vigente (o de recuperación). */
    public function disable(User $user, string $password, ?string $code, ?string $recoveryCode): void
    {
        if (! $user->hasMfaEnabled()) {
            throw MfaException::notEnabled();
        }
        if (! Hash::check($password, (string) $this->row($user)->password)) {
            $this->reserveAttempt($user, 'disable');
            $this->fail($user, 'disable');
        }
        $this->verify($user, $code, $recoveryCode, 'disable');
        $this->clear($user);
        $this->audit->log('mfa.disabled', $user, ['by' => 'self'], null, $user->getKey());
    }

    /** Borra el estado del segundo factor (desactivación propia o restablecimiento por la plataforma). */
    public function clear(User $user): void
    {
        DB::table('users')->where('id', $user->getKey())->update([
            'mfa_secret_encrypted' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null,
            'mfa_failed_attempts' => 0, 'mfa_locked_until' => null, 'mfa_last_used_step' => null,
        ]);
        $user->refresh();
    }

    /**
     * Genera códigos de recuperación nuevos (los anteriores dejan de valer). Exige un código TOTP vigente: nunca solo la sesión.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $user->hasMfaEnabled()) {
            throw MfaException::notEnabled();
        }
        $this->verifyTotp($user, $code, 'recovery_regenerate');
        $codes = RecoveryCodes::generate((int) config('security.mfa.recovery_codes', 10));
        DB::table('users')->where('id', $user->getKey())->update(['mfa_recovery_codes' => json_encode($codes['stored'], JSON_THROW_ON_ERROR)]);
        $user->refresh();
        $this->audit->log('mfa.recovery_regenerated', $user, ['codes_issued' => count($codes['plain'])], null, $user->getKey());

        return $codes['plain'];
    }

    /** @return array{enabled: bool, pending: bool, enabled_at: ?string, recovery_codes_remaining: int, recovery_codes_on_previous_key: int, locked_until: ?string} */
    public function status(User $user): array
    {
        $row = $this->row($user);
        $lockedUntil = $row->mfa_locked_until !== null ? Carbon::parse($row->mfa_locked_until) : null;

        return [
            'enabled' => $row->mfa_enabled_at !== null && $row->mfa_secret_encrypted !== null,
            'pending' => $row->mfa_secret_encrypted !== null && $row->mfa_enabled_at === null,
            'enabled_at' => $row->mfa_enabled_at !== null ? Carbon::parse($row->mfa_enabled_at)->toIso8601String() : null,
            'recovery_codes_remaining' => RecoveryCodes::remaining($row->mfa_recovery_codes !== null ? json_decode((string) $row->mfa_recovery_codes, true) : null),
            'recovery_codes_on_previous_key' => RecoveryCodes::onPreviousKey($row->mfa_recovery_codes !== null ? json_decode((string) $row->mfa_recovery_codes, true) : null),
            'locked_until' => $lockedUntil?->isFuture() ? $lockedUntil->toIso8601String() : null,
        ];
    }

    // ── Comprobaciones ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function verifyTotp(User $user, string $code, string $purpose): void
    {
        $this->reserveAttempt($user, $purpose);
        $row = $this->row($user);
        $step = null;
        if ($row->mfa_secret_encrypted !== null) {
            try {
                $secret = Crypt::decryptString((string) $row->mfa_secret_encrypted);
                $step = Totp::verify($secret, $code, now()->getTimestamp(), (int) config('security.mfa.window', 1), $this->period(), $this->digits(), 'sha1',
                    $row->mfa_last_used_step !== null ? (int) $row->mfa_last_used_step : null);
            } catch (DecryptException) {
                $step = null;   // la clave de la aplicación cambió: el secreto no se puede leer; solo valen los códigos de recuperación... que dependen de la misma clave
            }
        }
        // Reclamo atómico del paso: dos peticiones paralelas con el MISMO código no pasan las dos.
        $claimed = $step !== null && DB::table('users')->where('id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('mfa_last_used_step')->orWhere('mfa_last_used_step', '<', $step))
            ->update(['mfa_last_used_step' => $step, 'mfa_failed_attempts' => 0]) === 1;
        if (! $claimed) {
            $this->fail($user, $purpose);
        }
        $this->clearPair($user);
        $this->audit->log('mfa.verified', $user, ['method' => 'totp', 'purpose' => $purpose], null, $user->getKey());
    }

    private function verifyRecovery(User $user, string $code, string $purpose): void
    {
        $this->reserveAttempt($user, $purpose);
        $previousKey = false;
        $used = DB::transaction(function () use ($user, $code, &$previousKey): bool {
            $row = DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first(['mfa_recovery_codes']);
            $stored = $row?->mfa_recovery_codes !== null ? json_decode((string) $row->mfa_recovery_codes, true) : [];
            $match = RecoveryCodes::match(is_array($stored) ? $stored : [], $code);
            if ($match === null) {
                return false;
            }
            $previousKey = $match['previous_key'];
            $stored[$match['index']]['u'] = now()->toIso8601String();
            DB::table('users')->where('id', $user->getKey())->update(['mfa_recovery_codes' => json_encode($stored, JSON_THROW_ON_ERROR), 'mfa_failed_attempts' => 0]);

            return true;
        });
        if (! $used) {
            $this->fail($user, $purpose);
        }
        $this->clearPair($user);
        $this->audit->log('mfa.recovery_used', $user, ['purpose' => $purpose, 'previous_app_key' => $previousKey], null, $user->getKey());
    }

    /**
     * Reserva un intento ANTES de comprobar. Bloqueada la combinación persona + IP, la IP o la persona, o superado el máximo por carrera, no se comprueba nada y responde 423.
     */
    private function reserveAttempt(User $user, string $purpose): void
    {
        $now = now();
        $id = $user->getKey();
        $window = (int) config('security.mfa.lockout_seconds', 900);
        // Niveles 1 y 2 (caché): persona + IP e IP sola.
        foreach ([$this->lockKey('pair', $user), $this->lockKey('ip', $user)] as $lockKey) {
            $until = Cache::get($lockKey);
            if (is_numeric($until) && (int) $until > $now->getTimestamp()) {
                throw MfaException::locked((int) $until - $now->getTimestamp());
            }
        }
        $pair = $this->bump($this->counterKey('pair', $user), $window);
        $ip = $this->bump($this->counterKey('ip', $user), $window);
        if ($pair > (int) config('security.mfa.max_failed_attempts_per_ip_user', 5)) {
            $this->lockCache('pair', $user, $purpose, $window);

            throw MfaException::locked($window);
        }
        if ($ip > (int) config('security.mfa.max_failed_attempts_per_ip', 30)) {
            $this->lockCache('ip', $user, $purpose, $window);

            throw MfaException::locked($window);
        }
        // Nivel 3 (base de datos): la persona, desde todas las IP. Un bloqueo vencido se libera con el contador a cero.
        DB::table('users')->where('id', $id)->whereNotNull('mfa_locked_until')->where('mfa_locked_until', '<=', $now)
            ->update(['mfa_locked_until' => null, 'mfa_failed_attempts' => 0]);
        $row = DB::selectOne('UPDATE users SET mfa_failed_attempts = mfa_failed_attempts + 1 WHERE id = ? AND mfa_locked_until IS NULL RETURNING mfa_failed_attempts', [$id]);
        if ($row === null) {
            $until = DB::table('users')->where('id', $id)->value('mfa_locked_until');

            throw MfaException::locked($until !== null ? (int) $now->diffInSeconds(Carbon::parse($until), false) : $window);
        }
        if ((int) $row->mfa_failed_attempts > (int) config('security.mfa.max_failed_attempts', 15)) {
            $this->lockUser($user, $purpose);

            throw MfaException::locked($window);
        }
    }

    /** Un intento falló: si fue el último permitido de su nivel, bloquea; si no, 422 genérico. */
    private function fail(User $user, string $purpose): never
    {
        $attempts = (int) DB::table('users')->where('id', $user->getKey())->value('mfa_failed_attempts');
        $this->audit->log('mfa.failed', $user, ['purpose' => $purpose, 'attempts' => $attempts], null, $user->getKey());
        $this->lockIfAtLimit($user, $purpose, $attempts);

        throw MfaException::invalidCode();
    }

    private function failPassword(User $user, string $purpose): never
    {
        $attempts = (int) DB::table('users')->where('id', $user->getKey())->value('mfa_failed_attempts');
        $this->lockIfAtLimit($user, $purpose, $attempts);

        throw MfaException::invalidPassword();
    }

    /**
     * Al agotar el último intento permitido de un nivel, ese nivel se bloquea ya (423 en la misma respuesta), sin esperar a un intento más. Los niveles se evalúan TODOS: el que
     * llega a su límite en este intento se bloquea aunque otro también lo haga.
     */
    private function lockIfAtLimit(User $user, string $purpose, int $userAttempts): void
    {
        $window = (int) config('security.mfa.lockout_seconds', 900);
        $locked = false;
        if ((int) Cache::get($this->counterKey('pair', $user), 0) >= (int) config('security.mfa.max_failed_attempts_per_ip_user', 5)) {
            $this->lockCache('pair', $user, $purpose, $window);
            $locked = true;
        }
        if ((int) Cache::get($this->counterKey('ip', $user), 0) >= (int) config('security.mfa.max_failed_attempts_per_ip', 30)) {
            $this->lockCache('ip', $user, $purpose, $window);
            $locked = true;
        }
        if ($userAttempts >= (int) config('security.mfa.max_failed_attempts', 15)) {
            $this->lockUser($user, $purpose);
            $locked = true;
        }
        if ($locked) {
            throw MfaException::locked($window);
        }
    }

    private function lockUser(User $user, string $purpose): void
    {
        $seconds = (int) config('security.mfa.lockout_seconds', 900);
        DB::table('users')->where('id', $user->getKey())->update(['mfa_locked_until' => now()->addSeconds($seconds)]);
        $this->audit->log('mfa.locked', $user, ['purpose' => $purpose, 'seconds' => $seconds, 'scope' => 'user'], null, $user->getKey());
    }

    private function lockCache(string $level, User $user, string $purpose, int $seconds): void
    {
        Cache::put($this->lockKey($level, $user), now()->addSeconds($seconds)->getTimestamp(), $seconds);
        $this->audit->log('mfa.locked', $user, ['purpose' => $purpose, 'seconds' => $seconds, 'scope' => $level], null, $user->getKey());
    }

    /** Contador atómico con ventana (se crea con su TTL la primera vez). */
    private function bump(string $key, int $window): int
    {
        Cache::add($key, 0, $window);

        return (int) Cache::increment($key);
    }

    /** Un acierto reinicia el contador de la persona + IP (no el de la IP: es una ventana de tiempo). */
    private function clearPair(User $user): void
    {
        Cache::forget($this->counterKey('pair', $user));
        Cache::forget($this->lockKey('pair', $user));
    }

    private function counterKey(string $level, User $user): string
    {
        return 'mfa:fail:'.$level.':'.($level === 'pair' ? $user->getKey().':' : '').hash('sha256', $this->ip());
    }

    private function lockKey(string $level, User $user): string
    {
        return 'mfa:lock:'.$level.':'.($level === 'pair' ? $user->getKey().':' : '').hash('sha256', $this->ip());
    }

    private function ip(): string
    {
        return (string) (request()->ip() ?? 'cli');
    }

    private function row(User $user): object
    {
        return DB::table('users')->where('id', $user->getKey())->first(['password', 'mfa_secret_encrypted', 'mfa_enabled_at', 'mfa_recovery_codes', 'mfa_failed_attempts', 'mfa_locked_until', 'mfa_last_used_step'])
            ?? throw MfaException::notEnabled();
    }

    private function issuer(): string
    {
        return (string) config('security.mfa.issuer', 'Predictily');
    }

    private function digits(): int
    {
        return (int) config('security.mfa.digits', 6);
    }

    private function period(): int
    {
        return (int) config('security.mfa.period', 30);
    }
}
