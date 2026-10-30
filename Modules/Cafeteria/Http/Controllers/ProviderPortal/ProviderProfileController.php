<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Application\Services\Portal\CafeteriaPortalSelector;
use Modules\Cafeteria\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use Modules\Providers\Contracts\Data\PortalAccount;
use Modules\Providers\Contracts\ProviderPortalAccount;
use Modules\Providers\Contracts\ProviderPortalSession;

class ProviderProfileController extends Controller
{
    use FormatsProviderPortalData;

    public function __invoke(Request $request, ProviderPortalSession $session, ProviderPortalAccount $accounts): Response
    {
        return $this->show($request, $session, $accounts);
    }

    public function show(Request $request, ProviderPortalSession $session, ProviderPortalAccount $accounts): Response
    {
        $account = $accounts->own($request);
        abort_if($account === null, 403, __('provider-portal.access_denied'));

        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);

        // A transport-only provider has no cafeteria location to frame the
        // page; its users still need their own account (and a forced password
        // change lands here before anything else opens).
        if ($provider === null && $session->actorHasService($request, 'transport')) {
            return Inertia::render('ProviderPortal/Transport/Profile', [
                'user' => $this->userPayload($account),
            ]);
        }

        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        return Inertia::render('Cafeteria/Portal/Profile', [
            ...$this->portalPayload($request, $session, $provider),
            'provider' => $this->providerOption($provider),
            'user' => $this->userPayload($account),
        ]);
    }

    /** @return array<string, mixed> */
    private function userPayload(PortalAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'username' => $account->username,
            'phone_number' => $account->phoneNumber,
        ];
    }

    public function update(Request $request, ProviderPortalAccount $accounts): RedirectResponse
    {
        // The user's own account: no cafeteria location is needed to change it.
        $account = $accounts->own($request);
        abort_if($account === null, 403, __('provider-portal.access_denied'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', "unique:provider_users,email,{$account->id}"],
            'phone_number' => ['nullable', 'string', 'max:30'],
        ]);

        $accounts->updateOwn($request, $validated['name'], $validated['email'] ?? null, $validated['phone_number'] ?? null);

        return back()->with('flash', ['message' => __('provider-portal.profile_updated'), 'type' => 'success']);
    }

    public function updatePassword(Request $request, ProviderPortalAccount $accounts): RedirectResponse
    {
        // The user's own account: no cafeteria location is needed to change it.
        abort_if($accounts->own($request) === null, 403, __('provider-portal.access_denied'));

        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $validated = $request->validate([
            'password' => $accounts->passwordRules($request),
        ]);

        $wasForced = $accounts->changeOwnPassword($request, $request->string('current_password')->toString(), $validated['password']);
        $request->session()->regenerate();

        if ($wasForced) {
            // The portal opens now: send the user to their home page.
            return redirect()->to($accounts->homeUrl($request))->with('flash', ['message' => __('provider-portal.password_updated'), 'type' => 'success']);
        }

        return back()->with('flash', ['message' => __('provider-portal.password_updated'), 'type' => 'success']);
    }
}
