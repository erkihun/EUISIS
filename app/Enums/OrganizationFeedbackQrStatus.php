<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an organization's public feedback QR. `disabled` is a pause
 * an administrator can lift; `revoked` is final — the token never resolves
 * again and is never reissued (brief §11, 12).
 */
enum OrganizationFeedbackQrStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Revoked = 'revoked';

    public function acceptsFeedback(): bool
    {
        return $this === self::Active;
    }

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Active => $locale === 'am' ? 'ንቁ' : 'Active',
            self::Disabled => $locale === 'am' ? 'ታግዷል' : 'Disabled',
            self::Revoked => $locale === 'am' ? 'ተሰርዟል' : 'Revoked',
        };
    }
}
