<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

final readonly class ProviderDetails
{
    public function __construct(
        public string $nameEn,
        public ?string $nameAm,
        public ?string $contactPerson,
        public ?string $phoneNumber,
        public ?string $email,
        public ?string $address,
        /** Null keeps the current status. */
        public ?string $status,
        /** Merged over the provider's current metadata. */
        public array $metadata,
        public ?int $actorId,
    ) {}
}
