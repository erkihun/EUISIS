<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /core/audit: the audit log seen from Core. Ready-made views of the
 * master-data, card, account and integration trails, each counted over the
 * last 30 days inside the viewer's organization scope, opening the existing
 * audit page with the matching filter. Values are shown only there, where
 * personal fields stay masked for viewers without `employees.viewPii`.
 */
class CoreAuditCenterController extends Controller
{
    private const WINDOW_DAYS = 30;

    private const CACHE_SECONDS = 300;

    /** @var array<string, array{event_type?: string, principal?: string}> view key => audit page filter */
    private const VIEWS = [
        'organizations' => ['event_type' => 'organization'],
        'hierarchy' => ['event_type' => 'hierarchy'],
        'positions' => ['event_type' => 'position'],
        'employees' => ['event_type' => 'employee'],
        'assignments' => ['event_type' => 'assignment'],
        'cards' => ['event_type' => 'card'],
        'code_rules' => ['event_type' => 'code'],
        'accounts' => ['event_type' => 'user'],
        'roles' => ['event_type' => 'role'],
        'permissions' => ['event_type' => 'permission'],
        'sign_in' => ['event_type' => 'mfa'],
        'passwords' => ['event_type' => 'password'],
        'integrations' => ['event_type' => 'integration'],
        'machine' => ['principal' => 'system'],
    ];

    public function __invoke(Request $request, OrganizationScopeService $scope): Response
    {
        $this->authorize('viewAny', AuditLog::class);
        $user = $request->user();
        $scopeKey = $scope->isUnrestricted($user) ? 'all' : sha1(implode(',', $scope->allowedOrganizationIds($user)));

        $counts = Cache::remember('core.audit-center.'.$scopeKey, self::CACHE_SECONDS, function () use ($scope, $user): array {
            $since = now()->subDays(self::WINDOW_DAYS);
            $counts = [];
            foreach (self::VIEWS as $key => $filter) {
                $query = AuditLog::query()->where('created_at', '>=', $since)
                    ->when(isset($filter['event_type']), fn ($q) => $q->where('event_type', 'like', $filter['event_type'].'%'))
                    ->when(($filter['principal'] ?? null) === 'system', fn ($q) => $q->whereNull('actor_user_id'));
                $counts[$key] = $scope->applyOrganizationScope($query, $user)->count();
            }

            return $counts;
        });

        $from = now()->subDays(self::WINDOW_DAYS)->toDateString();

        return Inertia::render('Core/AuditCenter', [
            'views' => collect(self::VIEWS)->map(fn (array $filter, string $key): array => [
                'key' => $key,
                'count' => $counts[$key] ?? 0,
                'href' => route('audit-logs.index', [...$filter, 'from' => $from]),
            ])->values(),
            'windowDays' => self::WINDOW_DAYS,
        ]);
    }
}
