<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Data;

/** An organization as Reporting needs it: a reference and the names to snapshot. */
final readonly class OrganizationSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public ?string $nameEn,
        public ?string $nameAm,
        public bool $active,
    ) {}

    /** @return array{id: string, code: string, name_en: ?string, name_am: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name_en' => $this->nameEn, 'name_am' => $this->nameAm];
    }
}
