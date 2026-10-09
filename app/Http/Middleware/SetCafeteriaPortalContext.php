<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderUser;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares cafeteria provider portal context as Inertia props.
 * Uses the cafeteria_provider guard — provider identity comes directly from
 * the authenticated CafeteriaProviderUser, not from web-guard permissions.
 */
final class SetCafeteriaPortalContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var CafeteriaProviderUser|null $providerUser */
        $providerUser = auth('cafeteria_provider')->user();
        $provider = $providerUser?->cafeteriaProvider;

        Inertia::share('cafeteriaPortal', [
            'isPortal' => true,
            'selectedProvider' => $provider instanceof CafeteriaProvider
                ? $this->providerPayload($provider)
                : null,
        ]);

        Inertia::share('cafeteriaProviderAuth', [
            'user' => $providerUser ? [
                'id' => $providerUser->id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'initials' => $providerUser->initials(),
                'status' => $providerUser->status,
                'must_change_password' => $providerUser->must_change_password,
            ] : null,
            'provider' => $provider instanceof CafeteriaProvider
                ? $this->providerPayload($provider)
                : null,
        ]);

        return $next($request);
    }

    /** @return array<string, mixed> */
    private function providerPayload(CafeteriaProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name_en' => $provider->name_en,
            'name_am' => $provider->name_am,
            'location' => $provider->location,
        ];
    }
}
