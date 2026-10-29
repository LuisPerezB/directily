<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use Carbon\CarbonImmutable;

final class StagingResult
{
    public function __construct(
        public int $rows = 0,
        public int $pages = 0,
        public ?CarbonImmutable $maxRecordedAt = null,
        public ?string $minEventDate = null,
        public ?string $maxEventDate = null,
    ) {}
}
