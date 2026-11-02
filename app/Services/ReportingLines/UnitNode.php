<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

/** One organization unit as the reporting graph sees it (ADR 0025). */
final readonly class UnitNode
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $parentId,
        public bool $isActive,
        public string $nameEn,
        public ?string $nameAm,
        public ?string $code,
    ) {}
}
