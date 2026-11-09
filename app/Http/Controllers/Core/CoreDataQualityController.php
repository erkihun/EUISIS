<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\BackboneIntegrityChecks;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /core/data-quality (Core Stage 8, TD-20): the Golden Path integrity checks
 * with their counts and, for each finding, a link to the record. The checks run
 * across the whole city, so the page is for city-wide auditors only; a scoped
 * auditor sees the figures that concern them in the Audit Center instead.
 * Read-only: corrections go through the records' own Core actions.
 */
class CoreDataQualityController extends Controller
{
    private const SAMPLE = 20;

    private const ROUTES = ['position' => 'positions.show', 'employee' => 'employees.show', 'card' => 'id-cards.show'];

    public function __invoke(Request $request, BackboneIntegrityChecks $checks, OrganizationScopeService $scope): Response
    {
        abort_unless($scope->isUnrestricted($request->user()), 403);

        $counts = $checks->counts();

        return Inertia::render('Core/DataQuality', [
            'checks' => collect(BackboneIntegrityChecks::LABELS)->keys()->map(function (string $key) use ($checks, $counts): array {
                [, $kind] = BackboneIntegrityChecks::SUBJECTS[$key];

                return [
                    'key' => $key,
                    'count' => $counts[$key] ?? 0,
                    'kind' => $kind,
                    'records' => ($counts[$key] ?? 0) === 0 ? [] : array_map(
                        fn (string $id): array => ['id' => $id, 'href' => route(self::ROUTES[$kind], $id)],
                        $checks->samples($key, self::SAMPLE),
                    ),
                ];
            })->values(),
            'sample' => self::SAMPLE,
        ]);
    }
}
