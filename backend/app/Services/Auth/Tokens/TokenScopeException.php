<?php

declare(strict_types=1);

namespace App\Services\Auth\Tokens;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Un token no puede emitir otro con más alcance que el suyo, ni para otra empresa (403 `token_scope_insufficient`). */
final class TokenScopeException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'token_scope_insufficient'], 403);
    }
}
