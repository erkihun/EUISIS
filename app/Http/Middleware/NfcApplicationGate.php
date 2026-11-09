<?php

namespace App\Http\Middleware;

use App\Models\ExternalApplication;
use App\Services\Nfc\NfcAudit;
use Closure;
use Illuminate\Http\Request;

final class NfcApplicationGate
{
    public function handle(Request $request, Closure $next)
    {
        $app = $request->user();
        $scope = collect($request->route()->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'api.scope:'));
        $scope = substr((string) $scope, strlen('api.scope:'));
        $reason = null;
        if (! $app instanceof ExternalApplication || ! $app->isActive()) {
            $reason = 'APPLICATION_NOT_ALLOWED';
        } elseif (! $app->endpoints()->where('method', $request->method())->where('uri', '/'.$request->route()->uri())
            ->where('status', 'active')->wherePivot('is_enabled', true)->exists()) {
            $reason = 'ENDPOINT_NOT_ALLOWED';
        } elseif (! in_array($scope, $app->allowed_scopes ?? [], true) || ! $app->currentAccessToken()?->can($scope)) {
            $reason = 'SCOPE_MISSING';
        }
        if ($reason !== null) {
            app(NfcAudit::class)->record('application_denied', false, $reason, request: $request);

            return response()->json(['valid' => false, 'eligible' => false, 'reason_code' => $reason], 403);
        }

        return $next($request);
    }
}
