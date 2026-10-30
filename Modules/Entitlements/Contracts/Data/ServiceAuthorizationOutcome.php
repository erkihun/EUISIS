<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\Data;

/** Whether a card may take a service now, and the transaction recorded for it. */
final readonly class ServiceAuthorizationOutcome
{
    public function __construct(
        public bool $allowed,
        /** The eligibility result code (`eligible`, `no_active_entitlement`, `duplicate_transaction`, ...). */
        public string $resultCode,
        public ?string $transactionReference,
    ) {}
}
