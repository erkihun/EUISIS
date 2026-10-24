<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * API lifecycle (docs/governance/api-lifecycle.md): the partner OpenAPI
 * document and the registered /api/v1 routes must not drift apart. Every
 * documented path must exist; every partner route must be documented. The
 * backlog of undocumented routes was cleared on 2026-10-24 and must stay
 * empty: document a new route instead of listing it here.
 *
 * Terminal and device APIs (NFC, transport) have their own documents.
 */

const UNDOCUMENTED_PARTNER_ROUTES = [];

/** @return list<string> documented paths as route URIs, parameters normalised to {} */
function documentedPartnerPaths(): array
{
    $yaml = (string) file_get_contents(base_path('docs/integration/openapi-v1-partner.yaml'));
    preg_match_all('/^  (\/[^:\s]*):\s*$/m', $yaml, $matches);

    return array_map(fn (string $path): string => normalisedUri('api/v1'.$path), $matches[1]);
}

function normalisedUri(string $uri): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{}', $uri);
}

/** @return list<string> registered partner routes under /api/v1 */
function registeredPartnerUris(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => normalisedUri($route->uri()))
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/') && ! preg_match('#^api/v1/(nfc|transport)/#', $uri))
        ->unique()->values()->all();
}

it('documents only routes that exist', function (): void {
    $missing = array_values(array_diff(documentedPartnerPaths(), registeredPartnerUris()));

    expect($missing)->toBe([], 'The OpenAPI document lists paths with no route. Remove them or restore the route through the deprecation process.');
});

it('documents every partner route, apart from the tracked backlog', function (): void {
    $undocumented = array_values(array_diff(registeredPartnerUris(), documentedPartnerPaths()));
    sort($undocumented);
    $backlog = UNDOCUMENTED_PARTNER_ROUTES;
    sort($backlog);

    expect(array_values(array_diff($undocumented, $backlog)))->toBe([], 'A new /api/v1 route is not in docs/integration/openapi-v1-partner.yaml.')
        ->and(array_values(array_diff($backlog, $undocumented)))->toBe([], 'A backlog route is now documented or gone: remove it from UNDOCUMENTED_PARTNER_ROUTES.');
});
