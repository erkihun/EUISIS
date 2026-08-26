<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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

    public function minimumLength(): int
    {
        return max(8, (int) $this->settings->get('security', 'password_min_length', 12));
    }

    public function rule(?int $minimumLength = null, ?bool $complexityEnabled = null): Password
    {
        $minimumLength ??= $this->minimumLength();
        $complexityEnabled ??= (bool) $this->settings->get('security', 'password_complexity_enabled', true);

        $rule = Password::min(max(8, $minimumLength));

        return $complexityEnabled
            ? $rule->letters()->mixedCase()->numbers()->symbols()
            : $rule;
    }
}
