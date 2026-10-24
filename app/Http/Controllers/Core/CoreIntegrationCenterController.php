<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\ExternalApplication;
use App\Services\Integration\IntegrationMonitor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /core/integration: one page over the partner applications and the event
 * pipeline. Applications need `api_management.view`, events need
 * `integrations.view`; each half is left out without its permission.
 *
 * No secret, token, token hash or request header is read here: an application
 * is shown by name, code, status, bound, scope count and when it was last used.
 */
class CoreIntegrationCenterController extends Controller
{
    private const MAX_APPLICATIONS = 100;

    public function __invoke(Request $request, IntegrationMonitor $monitor): Response
    {
        $user = $request->user();
        $seesApplications = $user->can('api_management.view');
        $seesEvents = $user->can('integrations.view');
        abort_unless($seesApplications || $seesEvents, 403);

        $applications = $seesApplications
            ? ExternalApplication::query()
                ->select(['id', 'name', 'code', 'status', 'organization_access', 'allowed_scopes', 'last_used_at'])
                ->withCount('tokens')
                ->withMin('tokens', 'expires_at')
                ->orderBy('name')
                ->limit(self::MAX_APPLICATIONS)
                ->get()
                ->map(fn (ExternalApplication $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'code' => $application->code,
                    'status' => $application->status,
                    'organization_access' => $application->organization_access,
                    'scope_count' => count($application->allowed_scopes ?? []),
                    'token_count' => (int) $application->tokens_count,
                    'next_token_expiry' => $application->tokens_min_expires_at === null ? null : (string) $application->tokens_min_expires_at,
                    'last_used_at' => $application->last_used_at?->toIso8601String(),
                ])
                ->values()
            : null;

        return Inertia::render('Core/IntegrationCenter', [
            'applications' => $applications,
            'events' => $seesEvents ? $monitor->snapshot() : null,
            'can' => ['applications' => $seesApplications, 'events' => $seesEvents],
        ]);
    }
}
