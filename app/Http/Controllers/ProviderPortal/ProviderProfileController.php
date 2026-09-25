<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
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
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $providerUser = $context->providerUser($request);
        abort_if($providerUser === null, 403, __('provider-portal.access_denied'));

        return Inertia::render('Cafeteria/Portal/Profile', [
            ...$this->portalPayload($request, $context, $provider),
            'provider' => $this->providerOption($provider),
            'user' => [
                'id' => $providerUser->id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'phone_number' => $providerUser->phone_number,
            ],
        ]);
    }

    public function update(Request $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

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
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

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

        app(PasswordLifecycle::class)->change($providerUser, $validated['password'], AuditEventType::UserPasswordChanged, reason: 'provider_changed_own_password');
        $request->session()->regenerate();

        return back()->with('flash', ['message' => __('provider-portal.password_updated'), 'type' => 'success']);
    }
}
