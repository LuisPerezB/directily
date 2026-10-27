<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\Privacy\PiiMasker;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * "tap" de Monolog: enmascara datos personales en mensaje y contexto de cada log.
 * Configurado en config/logging.php para todos los canales de archivo.
 */
final class RedactPersonalData
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(static function (LogRecord $record): LogRecord {
                return $record->with(
                    message: PiiMasker::mask($record->message),
                    context: PiiMasker::sanitize($record->context),
                    extra: PiiMasker::sanitize($record->extra),
                );
            });
        }
    }
}
