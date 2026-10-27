<?php

declare(strict_types=1);

namespace App\Services\Imports;

/** Estados de import_jobs: uploaded → awaiting_mapping → validating → validated → importing → completed (o failed / rejected). */
final class ImportStatus
{
    public const UPLOADED = 'uploaded';
    public const AWAITING_MAPPING = 'awaiting_mapping';
    public const VALIDATING = 'validating';
    public const VALIDATED = 'validated';
    public const IMPORTING = 'importing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    public const MAPPABLE = [self::AWAITING_MAPPING, self::VALIDATED, self::FAILED];
    public const REJECTABLE = [self::AWAITING_MAPPING, self::VALIDATED, self::FAILED];
}
