<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/** A user's organization reach: city-wide, or the listed organizations (subtrees already expanded). */
final readonly class AccessScopeData
{
    /** @param list<string> $organizationUuids empty when unrestricted */
    public function __construct(
        public bool $unrestricted,
        public array $organizationUuids,
    ) {}
}
