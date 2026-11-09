<?php

declare(strict_types=1);

namespace Modules\Providers\Http\Requests;

use App\Security\LoginThrottle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Providers\Contracts\Data\ProviderPortalEntry;
use Modules\Providers\Domain\Models\ProviderUser;

/**
 * Handles provider portal login against the dedicated provider guard.
 */
class ProviderLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Sign the account in, through one service's door when $entry is given:
     * an account whose provider does not run that service is refused there
     * and pointed at the other door. The refusal counts as a failed attempt,
     * so the door cannot be used to test passwords past the lockout.
     */
    public function authenticate(?ProviderPortalEntry $entry = null): void
    {
        $throttle = app(LoginThrottle::class);
        $throttle->ensureNotLocked($this, 'provider', $this->string('identifier')->toString(), 'identifier');

        $identifier = $this->string('identifier')->toString();
        $password = $this->string('password')->toString();

        $providerUser = $this->resolveProviderUser($identifier);

        if ($providerUser === null || ! Hash::check($password, $providerUser->password)) {
            $throttle->failed($this, 'provider', $identifier);

            throw ValidationException::withMessages([
                'identifier' => __('provider-portal.login_failed'),
            ]);
        }

        if (! $providerUser->canLogin()) {
            $throttle->failed($this, 'provider', $identifier);

            $errorKey = ! $providerUser->isPortalEnabled()
                ? 'provider-portal.portal_disabled'
                : 'provider-portal.access_denied';

            throw ValidationException::withMessages([
                'identifier' => __($errorKey),
            ]);
        }

        if ($entry !== null && ! $providerUser->hasService($entry->serviceCode())) {
            $throttle->failed($this, 'provider', $identifier);

            throw ValidationException::withMessages([
                'identifier' => __('provider-portal.wrong_portal_'.$entry->value),
            ]);
        }

        // A legacy bcrypt hash becomes Argon2id on a successful sign-in.
        if (Hash::needsRehash($providerUser->password)) {
            $providerUser->forceFill(['password' => Hash::make($password)])->saveQuietly();
        }

        // No remember-me cookie: see LoginRequest::authenticate().
        Auth::guard('provider')->login($providerUser);

        $providerUser->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->ip(),
        ])->saveQuietly();

        $throttle->succeeded($this, 'provider', $identifier);
    }

    private function resolveProviderUser(string $identifier): ?ProviderUser
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return ProviderUser::with('provider.services.serviceType')
            ->where($field, $identifier)
            ->first();
    }
}
