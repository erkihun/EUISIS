<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * ADR 0002, decision 1: machine clients read master data and verify
 * credentials; they never create or change an organization, unit, position,
 * employee, assignment or card. Those changes are made by people in Core,
 * through the backbone commands, with their own audit trail.
 */

/** @return list<string> "METHOD uri" for every API route that is not a read */
function machineWriteRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with($route->uri(), 'api/')
            && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->map(fn (RouteDefinition $route): string => implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->sort()->values()->all();
}

it('offers machine clients no route that writes master data', function (): void {
    $master = '#^api/v1/(organizations|organization-units|positions|employees|assignments|id-cards|hierarchy|cards/(?!verify$))#';

    expect(collect(machineWriteRoutes())->filter(fn (string $route): bool => preg_match($master, explode(' ', $route)[1]) === 1)->values()->all())->toBe([]);
});

it('keeps the list of machine write routes to the ones reviewed against the decision', function (): void {
    // Each records a verification, a service transaction, a transport trip
    // step, a reconciliation request or a webhook replay; none changes a
    // master record. A new entry needs the same review (ADR 0002).
    expect(machineWriteRoutes())->toBe([
        'POST api/v1/cards/verify',
        'POST api/v1/changes/reconcile',
        'POST api/v1/nfc/challenges',
        'POST api/v1/nfc/service-eligibility',
        'POST api/v1/nfc/service-transactions/verify-and-record',
        'POST api/v1/nfc/verify',
        'POST api/v1/offline-sync/transactions',
        'POST api/v1/services/{serviceType}/authorize',
        'POST api/v1/services/{serviceType}/transactions',
        'POST api/v1/transport/boardings/sync',
        'POST api/v1/transport/boardings/verify',
        'POST api/v1/transport/device/heartbeat',
        'POST api/v1/transport/trips/{trip}/complete',
        'POST api/v1/transport/trips/{trip}/depart',
        'POST api/v1/transport/trips/{trip}/positions',
        'POST api/v1/transport/trips/{trip}/start',
        'POST api/v1/verifications',
        'POST api/v1/webhook-subscriptions/{subscription}/replay',
    ]);
});
