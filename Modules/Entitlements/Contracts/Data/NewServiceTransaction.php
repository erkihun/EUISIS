<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\Data;

use DateTimeInterface;

final readonly class NewServiceTransaction
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $employeeId,
        public string $cardId,
        public string $serviceTypeCode,
        public ?string $serviceProviderId,
        public string $status,
        public DateTimeInterface $occurredAt,
        public ?string $reference = null,
        public int|float|string|null $amount = null,
        public array $metadata = [],
        public ?string $entitlementId = null,
    ) {}
}
