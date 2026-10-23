<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\CoreWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Entering and leaving the Core workspace. */
class CoreWorkspaceController extends Controller
{
    public function __construct(private readonly CoreWorkspace $workspace) {}

    /**
     * GET /core/login: Core's own entry address. The one sign-in page serves
     * both; afterwards the person lands in Core, where `core.access` decides.
     */
    public function login(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('core.dashboard');
        }

        redirect()->setIntendedUrl(route('core.dashboard'));

        return redirect()->route('login');
    }

    /** POST /core/exit: back to the full application. */
    public function exit(Request $request): RedirectResponse
    {
        $this->workspace->leave($request->session());

        return redirect()->route('dashboard');
    }
}
