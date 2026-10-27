<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @param array{name: string, email: string, password: string} $data */
    public function register(array $data): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
        ]);
        $this->audit->log('auth.registered', $user, [], null, $user->id);

        return $user;
    }

    /** Valida credenciales. Lanza 422 genérico (no revela si el correo existe). */
    public function attempt(string $email, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        // Hash ficticio para igualar tiempos cuando el usuario no existe.
        $hash = $user?->password ?? '$2y$12$/QBV06KBUxjbV6X6sY3hpeiKkDCm/N4EAPWEscQIAFf2nicIbMv/C';
        if (! Hash::check($password, $hash) || $user === null) {
            $this->audit->log('auth.failed', null, ['reason' => 'invalid_credentials'], null, $user?->id);
            throw ValidationException::withMessages(['email' => 'Las credenciales no son válidas.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, [], null, $user->id);

        return $user;
    }
}
