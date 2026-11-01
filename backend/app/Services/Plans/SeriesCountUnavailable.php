<?php

declare(strict_types=1);

namespace App\Services\Plans;

use RuntimeException;

/** El conteo de series activas no se pudo completar dentro del tiempo acotado (no es un error de la petición). */
final class SeriesCountUnavailable extends RuntimeException {}
