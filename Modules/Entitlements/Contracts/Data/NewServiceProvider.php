<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\Data;

final readonly class NewServiceProvider
{
    public function __construct(
        public string $serviceTypeCode,
        public ?string $organizationId,
        public string $name,
        public string $code,
        public string $status = 'active',
        public bool $demo = false,
        public string $collisionPrefix = 'SP',
    ) {}
}
