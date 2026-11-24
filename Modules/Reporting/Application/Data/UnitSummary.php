<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Data;

/** A work unit inside an institution, as the Reporting module needs it. */
final readonly class UnitSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $code,
        public ?string $nameEn,
        public ?string $nameAm,
    ) {}

    /** @return array{id: string, organization_id: string, code: ?string, name_en: ?string, name_am: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organizationId, 'code' => $this->code, 'name_en' => $this->nameEn, 'name_am' => $this->nameAm];
    }
}
