<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Outbound SMS.
 *
 * The contract keeps registration and ID-check flows independent of the
 * configured SMS provider.
 */
interface SmsGateway
{
    /**
     * Deliver a message to one recipient.
     *
     * Implementations must not throw on a delivery failure: an SMS outage must
     * never abort the flow that triggered it. Return false and log instead.
     */
    public function send(string $phoneNumber, string $message): bool;

    /** Whether a real provider is configured and able to deliver. */
    public function isConfigured(): bool;
}
