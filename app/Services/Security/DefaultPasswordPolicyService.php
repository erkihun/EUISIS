<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * LEGACY shared default password — detection only.
 *
 * EUISIS no longer assigns one password to many accounts (new and reset
 * accounts get unique generated one-time passwords; see PasswordPolicy). A
 * hash configured before that change is kept so that an account still using
 * it is forced to change at sign-in, and so nobody can choose it again.
 */
final readonly class DefaultPasswordPolicyService
{
    public function __construct(private SystemSettingsService $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('security', 'default_password_enabled', false);
    }

    public function configuredHash(): ?string
    {
        $hash = $this->settings->get('security', 'default_password_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function isConfigured(): bool
    {
        return $this->configuredHash() !== null;
    }

    public function canSupplyInitialPassword(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function matches(?string $plainPassword): bool
    {
        $hash = $this->configuredHash();

        if ($plainPassword === null || $plainPassword === '' || $hash === null) {
            return false;
        }

        try {
            return Hash::check($plainPassword, $hash);
        } catch (RuntimeException) {
            return false;
        }
    }
}
