<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/** A user as other modules may show them: identity, name and home organization. No credentials, no contact beyond e-mail. */
final readonly class UserSummaryData
{
    public function __construct(
        public int $userId,
        public string $name,
        public ?string $email,
        public ?string $organizationUuid,
        public bool $active,
    ) {}
}
