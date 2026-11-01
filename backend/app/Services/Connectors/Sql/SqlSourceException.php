<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use RuntimeException;

/** Fallo de conexión o lectura de una fuente SQL. El mensaje NUNCA contiene credenciales. */
class SqlSourceException extends RuntimeException {}
