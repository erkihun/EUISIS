<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Providers\Contracts\Data\PortalAccount;

/**
 * The signed-in portal user's own account (ADR 0017): what the profile page
 * shows, and the only way a service module's portal changes it.
 */
interface ProviderPortalAccount
{
    /** The signed-in portal user's account; null when nobody is signed in to the portal. */
    public function own(Request $request): ?PortalAccount;

    /** A portal user's account by id (a policy given the guard's user); null when there is none. */
    public function find(string $portalUserId): ?PortalAccount;

    /**
     * The validation rules a new password of the signed-in user must pass
     * (the password policy, with the user's identity and history).
     *
     * @return list<mixed>
     */
    public function passwordRules(Request $request): array;

    public function updateOwn(Request $request, string $name, ?string $email, ?string $phoneNumber): void;

    /**
     * Changes the signed-in user's password once the current one is confirmed.
     * Returns whether the change had been forced (first sign-in, reset).
     *
     * @throws ValidationException on 'current_password' when it is wrong
     */
    public function changeOwnPassword(Request $request, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): bool;

    /** Where the signed-in user lands when the portal opens (the service dashboard their provider runs). */
    public function homeUrl(Request $request): string;
}
