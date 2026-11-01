<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use RuntimeException;

/** No se pudo conservar el original en Bronze: la carga se detiene (hallazgo BLOCKER `bronze_capture_failed`). */
final class BronzeCaptureException extends RuntimeException {}
