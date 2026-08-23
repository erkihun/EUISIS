<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Outbound SMS.
 *
 * No provider is integrated yet — see LogSmsGateway and the README blocker.
 * The contract exists so calling code is written once and a real gateway can
 * be bound without touching a caller.
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
