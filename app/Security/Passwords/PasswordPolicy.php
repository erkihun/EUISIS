<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Models\User;
use App\Security\Passwords\Rules\PasswordDoesNotContainPersonalData;
use App\Security\Passwords\Rules\PasswordIsNotCommon;
use App\Security\Passwords\Rules\PasswordNotCompromised;
use App\Security\Passwords\Rules\PasswordNotPreviouslyUsed;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * THE password policy. Every place a password is created, changed, reset or
 * assigned — for every account type — validates through `rules()`, so the
 * admin portal, employee accounts and provider portals cannot drift apart.
 *
 * NIST SP 800-63B-4 aligned (docs/password-security-policy.md): length,
 * blocklists, breach corpus, personal-information and reuse checks. No
 * composition rules, no periodic expiry.
 */
final class PasswordPolicy
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly CompromisedPasswordChecker $checker,
    ) {}

    public function minLength(): int
    {
        $floor = (int) config('security.passwords.minimum_length_floor', 15);

        return min($this->maxLength(), max($floor, (int) $this->setting('password_min_length', $floor)));
    }

    public function maxLength(): int
    {
        $floor = (int) config('security.passwords.maximum_length_floor', 64);
        $ceiling = (int) config('security.passwords.maximum_length_ceiling', 128);

        return max($floor, min($ceiling, (int) $this->setting('password_max_length', $ceiling)));
    }

    public function historyCount(): int
    {
        return max(0, min((int) config('security.passwords.history_max', 24), (int) $this->setting('password_history_count', 5)));
    }

    public function blocksPersonalInformation(): bool
    {
        return (bool) $this->setting('password_block_personal_info', true);
    }

    public function blocksCommonPasswords(): bool
    {
        return (bool) $this->setting('password_block_common', true);
    }

    public function checksBreaches(): bool
    {
        return (bool) config('security.passwords.breach_check.enabled', true)
            && (bool) $this->setting('password_breach_check', true);
    }

    /**
     * Validation rules for a password someone chooses.
     *
     * @param  Model|null  $account  the account it is for (history + personal data); null for a new account
     * @param  array<string, mixed>  $identity  personal values of a new account (name, email, username, ...)
     * @return list<mixed>
     */
    public function rules(?Model $account = null, array $identity = [], bool $confirmed = true): array
    {
        return array_values(array_filter([
            // Cheap checks first; `bail` stops before the history hashes and
            // the network check when the length is already wrong.
            'bail',
            'required',
            'string',
            'min:'.$this->minLength(),
            'max:'.$this->maxLength(),
            $confirmed ? 'confirmed' : null,
            $this->blocksCommonPasswords() ? new PasswordIsNotCommon : null,
            $this->blocksPersonalInformation() ? new PasswordDoesNotContainPersonalData(PersonalInformation::for($account, $identity)) : null,
            $account !== null ? new PasswordNotPreviouslyUsed($account, $this->historyCount()) : null,
            $this->checksBreaches() ? new PasswordNotCompromised($this->checker, $this->isPrivileged($account)) : null,
        ]));
    }

    /**
     * A one-time password for an administrator to hand over: 20 random
     * characters from an unambiguous alphabet (~114 bits), grouped for
     * reading aloud. Never derived from anything about the account.
     */
    public function generateTemporaryPassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($group = 0; $group < 4; $group++) {
            $chunk = '';
            for ($i = 0; $i < 5; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /** Accounts where a missing breach verdict is not good enough. */
    public function isPrivileged(?Model $account): bool
    {
        if (! $account instanceof User) {
            return false;
        }

        try {
            return $account->requiresMfa()
                || $account->hasAnyRole((array) config('security.mfa_privileged_roles', ['Super Admin', 'City Admin']));
        } catch (Throwable) {
            return true; // unknown: treat as privileged (fail closed)
        }
    }

    /**
     * What the browser needs for its advisory checklist. No hashes, no
     * history, nothing about other accounts.
     *
     * @return array<string, int|bool>
     */
    public function forClient(): array
    {
        return [
            'min_length' => $this->minLength(),
            'max_length' => $this->maxLength(),
            'history_count' => $this->historyCount(),
            'blocks_personal_information' => $this->blocksPersonalInformation(),
            'blocks_common_passwords' => $this->blocksCommonPasswords(),
            'checks_breaches' => $this->checksBreaches(),
        ];
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return $this->settings->get('security', $key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
