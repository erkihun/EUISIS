<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class EnsureProviderServiceScope
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->hasRole('Super Admin') || $user->hasRole('City Admin')) {
            return $next($request);
        }

        if (! $request->user()?->currentAccessToken()?->can('*') && ! $request->user()?->currentAccessToken()?->can('provider:access')) {
            $this->writeAuditLogAction->execute(
                AuditEventType::ProviderApiDenied,
                $user,
                reason: 'Missing provider token ability.',
                request: $request,
            );

            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $providerCode = $request->input('provider_code');
        $provider = $request->route('provider');

        if (! $provider instanceof ServiceProvider && is_string($providerCode) && $providerCode !== '') {
            $provider = ServiceProvider::query()->where('code', $providerCode)->first();
        }

        $serviceTypeCode = $request->route('serviceType') ?? $request->input('service_type');
        $serviceType = is_string($serviceTypeCode) && $serviceTypeCode !== ''
            ? ServiceType::query()->where('code', $serviceTypeCode)->first()
            : null;

        $scopes = $user->organizationScopes;

        if ($provider !== null) {
            $allowed = $scopes->contains(function ($scope) use ($provider, $serviceType): bool {
                $providerMatch = $scope->service_provider_id === null || $scope->service_provider_id === $provider->id;
                $serviceMatch = $serviceType === null || $scope->service_type_id === null || $scope->service_type_id === $serviceType->id;

                return $providerMatch && $serviceMatch;
            });

            if (! $allowed) {
                $this->writeAuditLogAction->execute(
                    AuditEventType::ProviderApiDenied,
                    $user,
                    $provider,
                    reason: 'Provider scope denied.',
                    request: $request,
                );

                return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
