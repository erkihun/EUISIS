<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asserts that the calling token carries a specific API scope.
 *
 * Usage: ->middleware('api.scope:id_cards.verify')
 *
 * Backwards compatibility is deliberate: tokens holding Sanctum's wildcard `*`
 * or the pre-existing `provider:access` ability are still accepted, so provider
 * portal tokens issued before scopes existed keep working. New external
 * applications are issued narrow scopes instead.
 */
readonly class EnsureApiScope
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->currentAccessToken();

        // Session-authenticated requests (no bearer token) are governed by the
        // usual policies rather than token abilities.
        if ($token === null) {
            return $next($request);
        }

        if ($token->can('*') || $token->can('provider:access') || $token->can($scope)) {
            return $next($request);
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::ProviderApiDenied,
            // The action records a human actor; an ExternalApplication is not
            // one, and passing it here threw a TypeError that turned every
            // scope denial for an external caller into a 500 instead of a 403.
            $user instanceof User ? $user : null,
            reason: $user instanceof ExternalApplication
                ? 'Missing API scope for application '.$user->code.': '.$scope
                : 'Missing API scope: '.$scope,
            request: $request,
        );

        return response()->json([
            'message' => 'Forbidden.',
            'error_code' => 'missing_scope',
            'required_scope' => $scope,
        ], Response::HTTP_FORBIDDEN);
    }
}
