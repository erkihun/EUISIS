<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\ApiScope;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asserts that the caller carries a specific API scope.
 *
 * Usage: ->middleware('api.scope:id_cards.verify') on integration routes,
 *        ->middleware('api.scope:id_cards.verify,provider') on the provider
 *        service routes.
 *
 * F1 (master backbone audit): `provider:access` used to satisfy EVERY scope,
 * so a provider-portal token was a city-wide employee-directory credential.
 * Now:
 *   - a registered application needs the exact scope on its token AND still in
 *     its allowed scopes, so removing a scope from an application takes effect
 *     at once without waiting for its tokens to expire;
 *   - a personal token needs the exact scope or `*`; `provider:access` counts
 *     only on routes marked `provider` and only for the provider-service
 *     scopes (ApiScope::PROVIDER_COMPATIBLE);
 *   - a session request (no token) passes here and is held to the person's own
 *     permission and organization scope by IntegrationAccessService.
 */
readonly class EnsureApiScope
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function handle(Request $request, Closure $next, string $scope, ?string $mode = null): Response
    {
        $principal = $request->user();

        if ($principal === null) {
            return ApiError::response($request, 401, 'unauthenticated', 'Unauthenticated.');
        }

        $token = method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        if ($principal instanceof ExternalApplication) {
            $granted = $token !== null && $token->can($scope) && in_array($scope, $principal->grantableScopes(), true);
        } elseif ($token === null) {
            // Session request: governed by the person's permissions downstream.
            return $next($request);
        } else {
            $granted = $token->can('*')
                || $token->can($scope)
                || ($mode === 'provider' && in_array($scope, ApiScope::PROVIDER_COMPATIBLE, true) && $token->can('provider:access'));
        }

        if ($granted) {
            return $next($request);
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::ProviderApiDenied,
            $principal instanceof User ? $principal : null,
            reason: $principal instanceof ExternalApplication
                ? 'Missing API scope for application '.$principal->code.': '.$scope
                : 'Missing API scope: '.$scope,
            request: $request,
        );

        return ApiError::response($request, 403, 'missing_scope', 'Forbidden.', ['required_scope' => $scope]);
    }
}
