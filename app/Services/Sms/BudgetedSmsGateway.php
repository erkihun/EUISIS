<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Services\Security\ExternalUsageBudgetService;
use Illuminate\Support\Facades\Auth;

/**
 * The SmsGateway every caller receives: the real gateway behind a spend cap.
 *
 * Registration OTP, the public ID Checker and contact verification all send
 * through the contract, so wrapping it here meters and caps all of them in
 * one place without touching any caller. A refused send returns false, which
 * every caller already treats as "not delivered", so no flow breaks.
 */
class BudgetedSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly HttpSmsGateway $inner,
        private readonly ExternalUsageBudgetService $budget,
    ) {}

    public function send(string $phoneNumber, string $message): bool
    {
        // An unconfigured provider costs nothing; do not meter it.
        if (! $this->inner->isConfigured()) {
            return $this->inner->send($phoneNumber, $message);
        }

        $reservation = $this->budget->reserve('sms', [
            'provider' => 'sms_http',
            'user_id' => Auth::guard('web')->id(),
            'recipient' => preg_replace('/\D+/', '', $phoneNumber),
        ]);

        if (! $reservation['allowed']) {
            return false;
        }

        $sent = $this->inner->send($phoneNumber, $message);
        $this->budget->settle($reservation['usage_id'], $sent);

        return $sent;
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured() && $this->budget->isEnabled('sms');
    }
}
