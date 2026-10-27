<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotResolvedException extends RuntimeException
{
    public function __construct(string $message = 'No hay una empresa activa en el contexto actual.')
    {
        parent::__construct($message);
    }
}
