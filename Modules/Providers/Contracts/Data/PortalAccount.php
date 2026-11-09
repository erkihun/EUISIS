<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

/** The signed-in portal user's own account, as the profile page shows it. */
final readonly class PortalAccount
{
    public function __construct(
        public string $id,
        public string $providerId,
        public string $name,
        public ?string $email,
        public ?string $username,
        public ?string $phoneNumber,
        /** The portal stays closed until the user sets a new password. */
        public bool $mustChangePassword,
        /** Active, portal-enabled, and of an active provider. */
        public bool $canSignIn,
    ) {}
}
