<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Services\MfaPolicy;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * An authenticated Core user who is still active and has replaced any one-time
 * password; with `writer`, one whose role may change master data (core_officer
 * or core_admin); with `admin`, a core_admin; with `city-admin`, a core_admin
 * for the whole city (settings, hierarchy versions, integrations).
 */
class EnsureCoreUser
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();
        // An integration client's token is not a person's sign-in: it reaches the /integration routes only.
        abort_if(! $user instanceof User || ! $user->is_active, 403, 'This Core account is not active.');
        if ($user->must_change_password && ! $request->is('api/auth/me', 'api/auth/password', 'api/auth/logout')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Choose a new password before continuing.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 403));
        }
        // A sign-in token issued before the second factor applied (or without it) reaches nothing but sign-out (ADR 0008).
        $token = $user->currentAccessToken();
        if (app(MfaPolicy::class)->applies($user) && ! $request->is('api/auth/logout')
            && ! ($token instanceof PersonalAccessToken && str_ends_with((string) $token->name, AuthController::MFA_TOKEN_SUFFIX))) {
            throw new HttpResponseException(response()->json([
                'message' => 'Multi-factor authentication is required for this account: sign in again with your authentication code.',
                'code' => $user->hasMfaEnabled() ? 'MFA_REQUIRED' : 'MFA_ENROLLMENT_REQUIRED',
            ], 403));
        }
        // No organization scope, no organizational data (ADR 0008): said plainly, not as empty lists.
        if (! $user->hasOrganizationScope() && $request->is('api/v1/*')) {
            throw new HttpResponseException(response()->json([
                'message' => EnsureOrganizationScope::MESSAGE,
                'code' => 'NO_ORGANIZATION_SCOPE',
            ], 403));
        }
        // The API's levels are permissions too (ADR 0009); each endpoint's action checks its own as well.
        abort_if($level === 'writer' && ! $user->can('core.write'), 403, 'Your Core role may not change master data.');
        abort_if($level === 'admin' && ! $user->can('core.administer'), 403, 'Only a Core administrator may change Core settings.');
        abort_if($level === 'city-admin' && (! $user->can('core.administer') || ! $user->isCityWide()), 403, 'This operation requires a city-wide Core administrator.');

        return $next($request);
    }
}
