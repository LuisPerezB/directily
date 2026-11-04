<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Exceptions;

use RuntimeException;

/** Una herramienta superó su plazo (conversation.gateway.tool_timeout_seconds): su resultado se descarta y la llamada se audita como `error` (reason: timeout). */
final class ToolTimeoutException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La herramienta superó su plazo.');
    }
}
