<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user('cafeteria');

        return array_merge(parent::share($request), [
            'auth' => [
                // Only what the UI needs — no password hash, no EUISIS data.
                'user' => $user === null ? null : [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'provider_id' => $user->provider_id,
                ],
                // Drives which navigation entries render. The server still
                // authorises every request — this only avoids showing an
                // operator links that would answer 403.
                'can' => $user === null ? null : [
                    'serve' => $user->canServe(),
                    'manage' => $user->canManage(),
                ],
                // Shown in the sidebar so an operator can confirm at a glance
                // which service point they are serving.
                'context' => $user === null ? null : [
                    'provider' => $user->provider?->only(['name', 'code']),
                    'cafeteria' => $user->cafeteria?->only(['name', 'code']),
                ],
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'type' => fn () => $request->session()->get('type'),
            ],
            // Drives the frontend dictionary and the calendar default.
            'locale' => app()->getLocale(),
            'supported_locales' => SetLocale::SUPPORTED,
        ]);
    }
}
