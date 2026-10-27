<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\SmsGateway as SmsGatewayContract;
use App\Services\SmsGateway;

/** The EUISIS SMS contract the provider portal's password reset uses, over the platform's SMS settings. */
final class SmsGatewayAdapter implements SmsGatewayContract
{
    public function __construct(private readonly SmsGateway $gateway) {}

    public function send(string $phoneNumber, string $message): bool
    {
        return $this->gateway->send($phoneNumber, $message);
    }

    public function isConfigured(): bool
    {
        return $this->gateway->isConfigured();
    }
}
