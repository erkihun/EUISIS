<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

/**
 * The provider-portal user acting: who to record as having scanned, planned,
 * submitted or disputed something, and whose provider they act for.
 */
final readonly class PortalActor
{
    public function __construct(
        public string $id,
        public string $providerId,
        public string $name,
    ) {}
}
