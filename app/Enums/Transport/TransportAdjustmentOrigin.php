<?php

declare(strict_types=1);

namespace App\Enums\Transport;

enum TransportAdjustmentOrigin: string
{
    /** Raised by the provider in the portal: missing transaction, device outage, … */
    case ProviderDispute = 'provider_dispute';
    /** Raised by the organization's reviewer. */
    case OrganizationAdjustment = 'organization_adjustment';
}
