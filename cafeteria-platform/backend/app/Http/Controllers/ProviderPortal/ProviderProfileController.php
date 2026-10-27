<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Models\ProviderUser;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderProfileController extends Controller
{
    use FormatsProviderPortalData;

    public function __invoke(Request $request, ProviderPortalContext $context): Response
    {
        return $this->show($request, $context);
    }

    public function show(Request $request, ProviderPortalContext $context): Response
    {
        $providerUser = $context->providerUser($request);
        abort_if($providerUser === null, 403, __('provider-portal.access_denied'));

        $provider = $context->selectedProvider($request);

        // A transport-only provider has no cafeteria location to frame the
        // page; its users still need their own account (and a forced password
        // change lands here before anything else opens).
        if ($provider === null && $providerUser->hasService('transport')) {
            return Inertia::render('ProviderPortal/Transport/Profile', [
                'user' => $this->userPayload($providerUser),
            ]);
        }

        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        return Inertia::render('Cafeteria/Portal/Profile', [
            ...$this->portalPayload($request, $context, $provider),
            'provider' => $this->providerOption($provider),
            'user' => $this->userPayload($providerUser),
        ]);
    }

    /** @return array<string, mixed> */
    private function userPayload(ProviderUser $providerUser): array
    {
        return [
            'id' => $providerUser->id,
            'name' => $providerUser->name,
            'email' => $providerUser->email,
            'username' => $providerUser->username,
            'phone_number' => $providerUser->phone_number,
        ];
    }

    public function update(Request $request, ProviderPortalContext $context): RedirectResponse
    {
        // The user's own account: no cafeteria location is needed to change it.
        $providerUser = $context->providerUser($request);
        abort_if($providerUser === null, 403, __('provider-portal.access_denied'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', "unique:provider_users,email,{$providerUser->id}"],
            'phone_number' => ['nullable', 'string', 'max:30'],
        ]);

        $providerUser->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
        ])->save();

        return back()->with('flash', ['message' => __('provider-portal.profile_updated'), 'type' => 'success']);
    }

    public function updatePassword(Request $request, ProviderPortalContext $context): RedirectResponse
    {
        // The user's own account: no cafeteria location is needed to change it.
        $providerUser = $context->providerUser($request);
        abort_if($providerUser === null, 403, __('provider-portal.access_denied'));

        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        // Current password first (the route is throttled), so the policy's
        // reuse check is never an oracle for someone without it.
        if (! Hash::check($request->string('current_password')->toString(), $providerUser->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('provider-portal.wrong_current_password'),
            ]);
        }

        $validated = $request->validate([
            'password' => app(PasswordPolicy::class)->rules($providerUser),
        ]);

        $wasForced = (bool) $providerUser->must_change_password;
        app(PasswordLifecycle::class)->change($providerUser, $validated['password'], AuditEventType::UserPasswordChanged, reason: 'provider_changed_own_password');
        $request->session()->regenerate();

        if ($wasForced) {
            // The portal opens now: send the user to their home page.
            return redirect()->to($context->homeUrl($providerUser->fresh()))->with('flash', ['message' => __('provider-portal.password_updated'), 'type' => 'success']);
        }

        return back()->with('flash', ['message' => __('provider-portal.password_updated'), 'type' => 'success']);
    }
}
