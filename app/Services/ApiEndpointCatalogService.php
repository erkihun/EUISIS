<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiEndpointDefinition;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Discovers the integration API surface from the Laravel route table and
 * reconciles it into the endpoint catalog.
 *
 * Reading the router — not a hand-maintained list — is the whole point: an
 * endpoint added in routes/api.php shows up in API Management without anyone
 * remembering to document it, which a maintained markdown guide had already
 * failed to do.
 *
 * Nothing here touches the database schema or reveals a token; it reports only
 * what the routing layer already declares publicly.
 */
class ApiEndpointCatalogService
{
    /**
     * Route prefixes that are never part of the public integration surface.
     * Profiling and debug tooling must not be advertised to external callers.
     */
    private const EXCLUDED_PREFIXES = [
        'api/telescope', 'telescope', 'horizon', 'pulse', '_debugbar', '_ignition',
        'api/debug', 'api/_', 'api/sanctum', 'sanctum',
    ];

    /**
     * Middleware that proves a route requires authentication.
     */
    private const AUTH_MIDDLEWARE = ['auth:sanctum', 'auth', 'auth:api', 'api.external'];

    /**
     * Every integration endpoint currently registered, sorted by URI.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function discover(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => $this->isIntegrationEndpoint($route))
            ->map(fn (RoutingRoute $route): array => $this->describe($route))
            ->unique(fn (array $endpoint): string => $endpoint['method'].' '.$endpoint['uri'])
            ->sortBy([['uri', 'asc'], ['method', 'asc']])
            ->values();
    }

    /**
     * Reconcile discovered routes into the catalog.
     *
     * Rows are never deleted — a removed endpoint is marked deprecated instead,
     * so historical request logs keep a definition to point at and an
     * accidental route removal is visible rather than silent.
     *
     * @return array{created: int, updated: int, deprecated: int, unchanged: int}
     */
    public function sync(?string $syncedBy = null): array
    {
        $discovered = $this->discover();
        $existing = ApiEndpointDefinition::query()->get()->keyBy($this->keyOf(...));

        $summary = ['created' => 0, 'updated' => 0, 'deprecated' => 0, 'unchanged' => 0];
        $seen = [];

        foreach ($discovered as $endpoint) {
            $key = $endpoint['method'].' '.$endpoint['uri'];
            $seen[] = $key;
            $record = $existing->get($key);

            if ($record === null) {
                ApiEndpointDefinition::query()->create($endpoint + [
                    'status' => ApiEndpointDefinition::STATUS_ACTIVE,
                    'is_public_documented' => true,
                    'created_by' => $syncedBy,
                    'last_synced_at' => now(),
                ]);
                $summary['created']++;

                continue;
            }

            // Discovered fields are authoritative; curated fields are not
            // touched so an administrator's description survives a re-sync.
            $changes = collect($endpoint)
                ->except(['method', 'uri'])
                ->reject(fn (mixed $value, string $field): bool => $record->{$field} == $value)
                ->all();

            // A route that came back after being deprecated is active again.
            if ($record->status === ApiEndpointDefinition::STATUS_DEPRECATED) {
                $changes['status'] = ApiEndpointDefinition::STATUS_ACTIVE;
            }

            if ($changes === []) {
                $record->forceFill(['last_synced_at' => now()])->save();
                $summary['unchanged']++;

                continue;
            }

            $record->fill($changes)->forceFill(['last_synced_at' => now()])->save();
            $summary['updated']++;
        }

        // Anything no longer registered is deprecated, never deleted.
        $summary['deprecated'] = $existing
            ->reject(fn (ApiEndpointDefinition $record): bool => in_array($this->keyOf($record), $seen, true))
            ->reject(fn (ApiEndpointDefinition $record): bool => $record->status === ApiEndpointDefinition::STATUS_DEPRECATED)
            ->each(fn (ApiEndpointDefinition $record) => $record->forceFill([
                'status' => ApiEndpointDefinition::STATUS_DEPRECATED,
                'last_synced_at' => now(),
            ])->save())
            ->count();

        return $summary;
    }

    private function keyOf(ApiEndpointDefinition $record): string
    {
        return $record->method.' '.$record->uri;
    }

    /**
     * Only versioned integration routes belong in the catalog.
     */
    private function isIntegrationEndpoint(RoutingRoute $route): bool
    {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/')) {
            return false;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return false;
            }
        }

        // Versioned groups (api/v1/...) are the published integration surface.
        return (bool) preg_match('#^api/v\d+/#', $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(RoutingRoute $route): array
    {
        $middleware = array_values(array_filter(
            $route->gatherMiddleware(),
            static fn (mixed $entry): bool => is_string($entry),
        ));

        return [
            'method' => $this->primaryMethod($route),
            'uri' => '/'.$route->uri(),
            'route_name' => $route->getName(),
            'controller_action' => $this->action($route),
            'required_scope' => $this->scope($middleware),
            'middleware' => $middleware,
            'auth_required' => $this->requiresAuth($middleware),
            'rate_limit' => $this->rateLimit($middleware),
            'version' => $this->version($route->uri()),
        ];
    }

    /**
     * HEAD and OPTIONS are registered automatically alongside GET; listing them
     * would triple the catalog without describing anything new.
     */
    private function primaryMethod(RoutingRoute $route): string
    {
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                return $method;
            }
        }

        return 'GET';
    }

    private function action(RoutingRoute $route): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure') {
            return null;
        }

        // Trim the namespace so the column stays readable in the table.
        return str_replace('App\\Http\\Controllers\\', '', $action);
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function scope(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'api.scope:')) {
                return substr($entry, strlen('api.scope:'));
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function requiresAuth(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (in_array($entry, self::AUTH_MIDDLEWARE, true) || str_starts_with($entry, 'auth:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function rateLimit(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'throttle:')) {
                return substr($entry, strlen('throttle:'));
            }
        }

        return null;
    }

    private function version(string $uri): ?string
    {
        return preg_match('#^api/(v\d+)/#', $uri, $matches) === 1 ? $matches[1] : null;
    }
}
