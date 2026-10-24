<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\CoreWorkspace;
use App\Services\Security\SessionActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** Entering and leaving the Core workspace. */
class CoreWorkspaceController extends Controller
{
    public function __construct(private readonly CoreWorkspace $workspace) {}

    /**
     * GET /core/login: Core's own sign-in screen (ADR 0002, decision 3). It is
     * the same sign-in as /login, titled for Core and without the employee
     * self-registration link: the form posts to the one login route, so
     * throttling, MFA, password policy and session handling are shared and a
     * person never signs in twice. Afterwards they land in Core, where
     * `core.access` decides.
     */
    public function login(Request $request): RedirectResponse|Response
    {
        if ($request->user() !== null) {
            return redirect()->route('core.dashboard');
        }

        redirect()->setIntendedUrl(route('core.dashboard'));

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
            'entry' => 'core',
        ]);
    }

    /** POST /core/exit: back to the full application. */
    public function exit(Request $request): RedirectResponse
    {
        $this->workspace->leave($request->session());

        return redirect()->route('dashboard');
    }
}
