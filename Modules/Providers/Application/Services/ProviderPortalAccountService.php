<?php

declare(strict_types=1);

namespace Modules\Providers\Application\Services;

use App\Enums\AuditEventType;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Providers\Contracts\Data\PortalAccount;
use Modules\Providers\Contracts\ProviderPortalAccount;
use Modules\Providers\Domain\Models\ProviderUser;

/** The signed-in portal user's own account (moved here from Cafeteria's profile page). */
final class ProviderPortalAccountService implements ProviderPortalAccount
{
    public function __construct(
        private readonly ProviderPortalContext $context,
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
    ) {}

    public function own(Request $request): ?PortalAccount
    {
        return $this->account($this->context->providerUser($request));
    }

    public function find(string $portalUserId): ?PortalAccount
    {
        return $this->account(ProviderUser::query()->find($portalUserId));
    }

    private function account(?ProviderUser $user): ?PortalAccount
    {
        return $user === null ? null : new PortalAccount(
            id: (string) $user->id,
            providerId: (string) $user->provider_id,
            name: (string) $user->name,
            email: $user->email,
            username: $user->username,
            phoneNumber: $user->phone_number,
            mustChangePassword: (bool) $user->must_change_password,
            canSignIn: $user->canLogin(),
        );
    }

    public function passwordRules(Request $request): array
    {
        return $this->policy->rules($this->signedIn($request));
    }

    public function updateOwn(Request $request, string $name, ?string $email, ?string $phoneNumber): void
    {
        $this->signedIn($request)->forceFill([
            'name' => $name,
            'email' => $email,
            'phone_number' => $phoneNumber,
        ])->save();
    }

    public function changeOwnPassword(Request $request, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): bool
    {
        $user = $this->signedIn($request);

        // Current password first (the route is throttled), so the policy's
        // reuse check is never an oracle for someone without it.
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('provider-portal.wrong_current_password'),
            ]);
        }

        $wasForced = (bool) $user->must_change_password;
        $this->lifecycle->change($user, $newPassword, AuditEventType::UserPasswordChanged, reason: 'provider_changed_own_password');

        return $wasForced;
    }

    public function homeUrl(Request $request): string
    {
        return $this->context->homeUrl($this->signedIn($request)->fresh());
    }

    private function signedIn(Request $request): ProviderUser
    {
        $user = $this->context->providerUser($request);
        abort_if($user === null, 403, __('provider-portal.access_denied'));

        return $user;
    }
}
