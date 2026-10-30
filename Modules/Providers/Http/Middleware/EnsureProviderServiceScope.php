<?php

declare(strict_types=1);

namespace Modules\Providers\Http\Middleware;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a caller of the provider service routes to the service provider(s)
 * it may act for.
 *
 * F16 (master backbone audit): the route group accepted a registered
 * application's token, then called `$user->hasRole()` on it — an
 * ExternalApplication has no roles, so every machine call ended in a 500 and
 * no provider check ever ran. Now the two principals are handled apart:
 *   - a registered application must be bound to exactly one service provider
 *     (external_applications.service_provider_id) and every provider the
 *     request names — route `{provider}`, `provider_code`, or each item of an
 *     offline batch — must be that one;
 *   - a person needs a token able to act for providers and a matching
 *     provider/service organization scope, as before, with the offline batch
 *     now checked item by item instead of only the top-level `provider_code`.
 */
readonly class EnsureProviderServiceScope
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        if ($principal === null) {
            return ApiError::response($request, 401, 'unauthenticated', 'Unauthenticated.');
        }

        $references = $this->referencedProviderCodes($request);

        if ($principal instanceof ExternalApplication) {
            return $this->handleApplication($request, $next, $principal, $references);
        }

        if (! $principal instanceof User) {
            return ApiError::response($request, 403, 'forbidden', 'Forbidden.');
        }

        if ($principal->hasRole('Super Admin') || $principal->hasRole('City Admin')) {
            return $next($request);
        }

        $token = $principal->currentAccessToken();
        $routeScope = $this->routeScope($request);

        if ($token === null || ! ($token->can('*') || $token->can('provider:access') || ($routeScope !== null && $token->can($routeScope)))) {
            $this->writeAuditLogAction->execute(AuditEventType::ProviderApiDenied, $principal, reason: 'Missing provider token ability.', request: $request);

            return ApiError::response($request, 403, 'missing_provider_ability', 'Forbidden.');
        }

        // A card check that names no provider would skip the provider scope
        // below and match an entitlement of any provider: fail closed.
        if ($references->isEmpty() && $request->routeIs('api.v1.cards.verify', 'api.v1.services.authorize')) {
            $this->writeAuditLogAction->execute(AuditEventType::ProviderApiDenied, $principal, reason: 'No service provider named.', request: $request);

            return ApiError::response($request, 422, 'provider_required', 'Name the service provider you act for (provider_code).');
        }

        $serviceTypeCode = $request->route('serviceType') ?? $request->input('service_type');
        $serviceType = is_string($serviceTypeCode) && $serviceTypeCode !== ''
            ? ServiceType::query()->where('code', $serviceTypeCode)->first()
            : null;

        $scopes = $principal->organizationScopes;

        foreach ($this->resolveProviders($request, $references) as $provider) {
            $allowed = $scopes->contains(function ($scope) use ($provider, $serviceType): bool {
                $providerMatch = $scope->service_provider_id === null || $scope->service_provider_id === $provider->id;
                $serviceMatch = $serviceType === null || $scope->service_type_id === null || $scope->service_type_id === $serviceType->id;

                return $providerMatch && $serviceMatch;
            });

            if (! $allowed) {
                $this->writeAuditLogAction->execute(AuditEventType::ProviderApiDenied, $principal, $provider, reason: 'Provider scope denied.', request: $request);

                return ApiError::response($request, 403, 'provider_scope_denied', 'Forbidden.');
            }
        }

        return $next($request);
    }

    /** @param  Collection<int, string>  $references */
    private function handleApplication(Request $request, Closure $next, ExternalApplication $application, Collection $references): Response
    {
        $bound = $application->service_provider_id;

        if ($bound === null) {
            $this->writeAuditLogAction->execute(AuditEventType::ProviderApiDenied, null, reason: 'Application '.$application->code.' is not bound to a service provider.', request: $request);

            return ApiError::response($request, 403, 'provider_binding_missing', 'This application is not bound to a service provider.');
        }

        foreach ($this->resolveProviders($request, $references) as $provider) {
            if ((string) $provider->getKey() !== (string) $bound) {
                $this->writeAuditLogAction->execute(AuditEventType::ProviderApiDenied, null, $provider, reason: 'Application '.$application->code.' acted for a provider it is not bound to.', request: $request);

                return ApiError::response($request, 403, 'provider_binding_denied', 'This application may not act for that service provider.');
            }
        }

        // A code that resolves to no provider is never silently accepted for a machine client.
        $known = ServiceProvider::query()->whereIn('code', $references->all())->pluck('code');
        if ($references->diff($known)->isNotEmpty()) {
            return ApiError::response($request, 403, 'provider_binding_denied', 'This application may not act for that service provider.');
        }

        return $next($request);
    }

    /**
     * Every provider code the request names, top level or per offline item.
     *
     * @return Collection<int, string>
     */
    private function referencedProviderCodes(Request $request): Collection
    {
        $codes = collect([$request->input('provider_code')]);

        foreach ((array) $request->input('transactions', []) as $transaction) {
            $codes->push(is_array($transaction) ? ($transaction['provider_code'] ?? null) : null);
        }

        return $codes->filter(fn ($code): bool => is_string($code) && $code !== '')->unique()->values();
    }

    /**
     * @param  Collection<int, string>  $codes
     * @return Collection<int, ServiceProvider>
     */
    private function resolveProviders(Request $request, Collection $codes): Collection
    {
        $providers = $codes->isEmpty() ? collect() : ServiceProvider::query()->whereIn('code', $codes->all())->get();

        $routeProvider = $request->route('provider');
        if ($routeProvider instanceof ServiceProvider) {
            $providers->push($routeProvider);
        }

        return $providers->unique(fn (ServiceProvider $provider): string => (string) $provider->getKey())->values();
    }

    private function routeScope(Request $request): ?string
    {
        $entry = collect($request->route()?->gatherMiddleware() ?? [])
            ->first(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'api.scope:'));

        if (! is_string($entry)) {
            return null;
        }

        return explode(',', substr($entry, strlen('api.scope:')))[0];
    }
}
