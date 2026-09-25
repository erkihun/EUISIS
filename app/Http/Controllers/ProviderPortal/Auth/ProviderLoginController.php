<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderPortal\ProviderLoginRequest;
use App\Services\Security\SessionActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderLoginController extends Controller
{
    public function __construct(private readonly SessionActivityService $sessionActivity) {}

    public function create(): Response
    {
        return Inertia::render('ProviderPortal/Auth/Login', [
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
        ]);
    }

    public function store(ProviderLoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $this->sessionActivity->startAuthenticatedSession($request->session());

        return redirect()->intended(route('provider.portal.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->sessionActivity->end($request, SessionActivityService::REASON_LOGGED_OUT, ['provider']);

        return redirect()->route('provider.portal.login');
    }
}
