<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructure\Exceptions;

use RuntimeException;

/**
 * Raised when master data has drifted since approval, so the approved change
 * can no longer be applied safely. Nothing is written: the caller rolls back
 * and parks the request as IMPLEMENTATION_BLOCKED with these reasons.
 */
final class ImplementationBlockedException extends RuntimeException
{
    /**
     * @param  array<int, array{code: string, context: array<string, mixed>}>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Implementation blocked by '.count($conflicts).' conflict(s).');
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return array_map(static fn (array $conflict): string => $conflict['code'], $this->conflicts);
    }
}
