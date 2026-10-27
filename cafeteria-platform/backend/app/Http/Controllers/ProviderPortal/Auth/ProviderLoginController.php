<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderPortal\ProviderLoginRequest;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Security\SessionActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderLoginController extends Controller
{
    public function __construct(
        private readonly SessionActivityService $sessionActivity,
        private readonly ProviderPortalContext $context,
    ) {}

    public function create(): Response
    {
        return Inertia::render('ProviderPortal/Auth/Login', [
            'status' => session('status'),
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
        ]);
    }

    public function store(ProviderLoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $this->sessionActivity->startAuthenticatedSession($request->session());

        /*
         * The intended URL is shared by every guard: one left by a staff page
         * would send the provider to the admin area and on to the staff login.
         */
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && str_starts_with($intended, url('provider/portal').'/')) {
            return redirect()->to($intended);
        }

        return redirect()->to($this->context->homeUrl($request->user('provider')));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->sessionActivity->end($request, SessionActivityService::REASON_LOGGED_OUT, ['provider']);

        return redirect()->route('provider.portal.login');
    }
}
