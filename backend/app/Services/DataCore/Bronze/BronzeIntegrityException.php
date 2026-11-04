<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use RuntimeException;

/** El contenido Bronze no coincide con su SHA-256, fue purgado o no existe en el almacén. */
final class BronzeIntegrityException extends RuntimeException {}
