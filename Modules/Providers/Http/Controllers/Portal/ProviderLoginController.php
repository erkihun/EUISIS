<?php

declare(strict_types=1);

namespace Modules\Providers\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Security\SessionActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Application\Services\ProviderPortalContext;
use Modules\Providers\Contracts\Data\ProviderPortalEntry;
use Modules\Providers\Http\Requests\ProviderLoginRequest;

/**
 * The provider portal's sign-in. Cafeteria and transport each have their own
 * page (/provider/portal/cafeteria/login, /provider/portal/transport/login);
 * /provider/portal/login lets a visitor choose, so printed and bookmarked
 * links keep working. All three sign in to the same `provider` guard.
 */
class ProviderLoginController extends Controller
{
    /** A year: the browser's portal is a convenience that outlives any one session. */
    private const ENTRY_COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly SessionActivityService $sessionActivity,
        private readonly ProviderPortalContext $context,
    ) {}

    /** /provider/portal/login: choose the cafeteria or the transport sign-in. */
    public function create(Request $request): Response
    {
        return Inertia::render('ProviderPortal/Auth/ChoosePortal', [
            'status' => session('status'),
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
            'lastPortal' => ProviderPortalEntry::tryFrom((string) $request->cookie(ProviderPortalEntry::COOKIE))?->value,
        ]);
    }

    public function createCafeteria(): Response
    {
        return $this->page(ProviderPortalEntry::Cafeteria);
    }

    public function createTransport(): Response
    {
        return $this->page(ProviderPortalEntry::Transport);
    }

    public function storeCafeteria(ProviderLoginRequest $request): RedirectResponse
    {
        return $this->signIn($request, ProviderPortalEntry::Cafeteria);
    }

    public function storeTransport(ProviderLoginRequest $request): RedirectResponse
    {
        return $this->signIn($request, ProviderPortalEntry::Transport);
    }

    /**
     * The undivided sign-in, kept for forms loaded before the split: any
     * active account, landing on its provider's home.
     */
    public function store(ProviderLoginRequest $request): RedirectResponse
    {
        return $this->signIn($request, null);
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Resolved first: it reads the portal this browser uses, not the session being ended.
        $loginUrl = $this->context->loginUrl($request);

        $this->sessionActivity->end($request, SessionActivityService::REASON_LOGGED_OUT, ['provider']);

        return redirect()->to($loginUrl);
    }

    private function page(ProviderPortalEntry $entry): Response
    {
        $component = $entry === ProviderPortalEntry::Cafeteria ? 'CafeteriaLogin' : 'TransportLogin';

        return Inertia::render('ProviderPortal/Auth/'.$component, [
            'status' => session('status'),
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
        ]);
    }

    private function signIn(ProviderLoginRequest $request, ?ProviderPortalEntry $entry): RedirectResponse
    {
        $request->authenticate($entry);
        $request->session()->regenerate();
        $this->sessionActivity->startAuthenticatedSession($request->session());

        $providerUser = $request->user('provider');

        if ($entry !== null) {
            cookie()->queue(cookie(
                ProviderPortalEntry::COOKIE,
                $entry->value,
                self::ENTRY_COOKIE_MINUTES,
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        /*
         * The intended URL is shared by every guard: one left by a staff page
         * would send the provider to the admin area and on to the staff login.
         */
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && str_starts_with($intended, url('provider/portal').'/')) {
            return redirect()->to($intended);
        }

        return redirect()->to(match ($entry) {
            ProviderPortalEntry::Cafeteria => route('provider.portal.dashboard'),
            ProviderPortalEntry::Transport => route('provider.portal.transport.dashboard'),
            null => $this->context->homeUrl($providerUser),
        });
    }
}
