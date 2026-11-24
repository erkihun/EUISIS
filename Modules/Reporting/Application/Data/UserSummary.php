<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Data;

/** A responsible person as Reporting needs them: a reference and the name to snapshot. */
final readonly class UserSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public ?string $organizationId,
    ) {}

    /** @return array{id: int, name: string, email: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }
}
