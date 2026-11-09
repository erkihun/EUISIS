<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

use InvalidArgumentException;

/**
 * Changes to a provider's record: only the fields given change, every other
 * field keeps its stored value (an edit form that leaves a field out).
 */
final readonly class ProviderRevision
{
    public const FIELDS = [
        'provider_code',
        'name_en',
        'name_am',
        'assigned_scope_type',
        'assigned_organization_id',
        'contact_person',
        'phone_number',
        'email',
        'address',
        'status',
    ];

    /** @param array<string, mixed> $changes field => new value, fields from FIELDS only */
    public function __construct(
        public array $changes,
        public ?int $actorId,
    ) {
        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Not a provider field: '.implode(', ', $unknown));
        }
    }
}
