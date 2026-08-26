<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an employee's public feedback QR token.
 *
 * `suspended` is reversible (a token paused during an investigation);
 * `revoked` is terminal — regenerating issues a brand new token rather than
 * reviving a revoked one, so a printed QR that leaked can never come back.
 */
enum FeedbackTokenStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function acceptsFeedback(): bool
    {
        return $this === self::Active;
    }
}
