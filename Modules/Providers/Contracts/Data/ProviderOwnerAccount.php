<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

/** The first portal account of a newly registered provider. */
final readonly class ProviderOwnerAccount
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $username,
        /** A policy-checked or generated one-time password; never empty. */
        public string $initialPassword,
    ) {}
}
