<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

final readonly class ProviderSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $nameEn,
        public ?string $nameAm,
        public string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
