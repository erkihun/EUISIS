<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Modules\Court\Domain\Models\IntegrationClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/v1/integration/*: a bearer token issued by
 * `php artisan euisis:integration-token`, not revoked, from an allowed IP,
 * holding the ability the route names (`integration.client:referrals:read`).
 * These tokens are not Sanctum tokens, so no person-facing route accepts them.
 */
class AuthenticateIntegrationClient
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = (string) $request->bearerToken();
        $client = $token === '' ? null : IntegrationClient::findByToken($token);

        if ($client === null) {
            return $this->deny($request, 401, 'unauthenticated', 'A valid integration token is required.');
        }
        if (! $client->allowsIp($request->ip())) {
            Log::warning('Integration call from a disallowed IP', ['client' => $client->name, 'ip' => $request->ip()]);

            return $this->deny($request, 403, 'ip_not_allowed', 'This address may not call the integration API.');
        }
        if (! $client->hasAbility($ability)) {
            return $this->deny($request, 403, 'ability_missing', 'The token lacks the '.$ability.' ability.');
        }

        $client->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        $request->attributes->set('integration_client', $client);

        return $next($request);
    }

    private function deny(Request $request, int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
            'request_id' => $request->attributes->get('request_id'),
        ], $status);
    }
}
