<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BreakGlassGrant;
use App\Models\CardRequest;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CoreSettings;
use App\Services\IntegrityChecks;
use App\Services\OrganizationScope;
use App\Support\CorePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Core workspace pages of EUISIS (App\Http\Controllers\Core\*): the
 * dashboard, assignments, audit centre, data quality, settings and the audit
 * log, with the same props as there, read from the Core tables.
 */
class CoreController extends Controller
{
    private const EXPIRY_WINDOW_DAYS = 30;

    /** EUISIS CoreDashboardMetrics::METRICS, limited to what the Core holds. */
    private const METRICS = [
        'organizations_active' => ['domain' => 'organization', 'permission' => 'organizations.view', 'citywide' => false],
        'units_active' => ['domain' => 'organization', 'permission' => 'organization-units.viewAny', 'citywide' => false],
        'hierarchy_version' => ['domain' => 'organization', 'permission' => 'hierarchy-versions.viewAny', 'citywide' => false],
        'positions_active' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'positions_inactive' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'positions_held' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'positions_without_holder' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'employees_active' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'assignments_current' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'employees_unplaced' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => true],
        'cards_active' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'cards_expiring' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'card_requests_open' => ['domain' => 'credentials', 'permission' => 'id-cards.viewAny', 'citywide' => false],
        'cards_suspended' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'cards_revoked' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'users_active' => ['domain' => 'governance', 'permission' => 'users.viewAny', 'citywide' => false],
        'audit_events_24h' => ['domain' => 'governance', 'permission' => 'audit.view', 'citywide' => true],
        'data_quality_findings' => ['domain' => 'quality', 'permission' => 'audit.view', 'citywide' => true],
        // P4/P5 (ADR 0011): the operational KPIs, each in the reader's scope.
        'organizations_total' => ['domain' => 'organization', 'permission' => 'organizations.view', 'citywide' => false],
        'employees_total' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'employees_pending_verification' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'employees_pending_approval' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'positions_vacant' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'positions_utilization' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'card_coverage' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'cards_lost_replaced' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'card_approvals_pending' => ['domain' => 'credentials', 'permission' => 'id-cards.viewAny', 'citywide' => false],
        'data_quality_critical' => ['domain' => 'quality', 'permission' => 'data_quality.view', 'citywide' => false],
        'data_quality_open' => ['domain' => 'quality', 'permission' => 'data_quality.view', 'citywide' => false],
        'hierarchy_pending' => ['domain' => 'organization', 'permission' => 'hierarchy-versions.viewAny', 'citywide' => true],
        'webhooks_failed' => ['domain' => 'operations', 'permission' => 'integrations.view', 'citywide' => true],
        'api_errors_24h' => ['domain' => 'operations', 'permission' => 'integrations.view', 'citywide' => true],
        'failed_jobs' => ['domain' => 'operations', 'permission' => 'system_health.view', 'citywide' => true],
        'break_glass_active' => ['domain' => 'operations', 'permission' => 'security_dashboard.view', 'citywide' => true],
        'mfa_coverage' => ['domain' => 'operations', 'permission' => 'security_dashboard.view', 'citywide' => true],
    ];

    private const DOMAIN_LINKS = [
        'organization' => ['organizations.index' => 'organizations.view', 'organization-units.index' => 'organization-units.viewAny'],
        'positions' => ['positions.index' => 'positions.viewAny'],
        'employees' => ['employees.index' => 'employees.view'],
        'credentials' => ['id-cards.index' => 'cards.view', 'card-requests.index' => 'id-cards.viewAny'],
        'governance' => ['users.index' => 'users.viewAny', 'audit-logs.index' => 'audit.view'],
        'quality' => ['core.data-quality' => 'data_quality.view'],
        'operations' => ['core.system-health' => 'system_health.view', 'core.security' => 'security_dashboard.view'],
    ];

    private const ACTIVITY_PREFIXES = ['organization', 'unit', 'hierarchy', 'position', 'establishment', 'employee', 'assignment', 'card'];

    /** EUISIS CoreAuditCenterController::VIEWS, with the Core's action names. */
    private const AUDIT_VIEWS = [
        'organizations' => ['event_type' => 'organization'],
        'positions' => ['event_type' => 'position'],
        'employees' => ['event_type' => 'employee'],
        'assignments' => ['event_type' => 'assignment'],
        'cards' => ['event_type' => 'card'],
        'accounts' => ['event_type' => 'user'],
        'passwords' => ['event_type' => 'user.password'],
        'sign_in' => ['event_type' => 'auth'],
    ];

    private const AUDIT_WINDOW_DAYS = 30;

    public function __construct(private readonly OrganizationScope $scope) {}

    public function dashboard(Request $request, IntegrityChecks $checks): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->organizationIds($user);
        $values = $this->metricValues($user, $ids, $checks);

        $domains = [];
        foreach (self::METRICS as $key => $metric) {
            if (($metric['citywide'] && $ids !== null) || ! $user->can($metric['permission'])) {
                continue;
            }
            $domains[$metric['domain']] ??= ['key' => $metric['domain'], 'href' => $this->domainLink($user, $metric['domain']), 'metrics' => []];
            $domains[$metric['domain']]['metrics'][] = ['key' => $key, 'value' => $values[$key] ?? null];
        }

        return Inertia::render('Core/Dashboard', [
            'metrics' => [
                'citywide' => $ids === null,
                'organization_count' => $ids === null ? null : count($ids),
                'domains' => array_values($domains),
            ],
            'recent' => $this->recentActivity($user),
        ]);
    }

    public function assignments(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in([EmployeeAssignment::ACTIVE, EmployeeAssignment::CLOSED])],
            'history' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $organizationCode = trim((string) ($filters['organization'] ?? ''));
        $withHistory = (bool) ($filters['history'] ?? false);

        $query = EmployeeAssignment::query()
            ->with(['employee:id,employee_number,full_name,status', 'organization:id,code,name_en,name_am', 'unit:id,code,name_en,name_am', 'position:id,job_position_code,title_en,title_am'])
            ->when(! $withHistory, fn ($q) => $q->where('is_current', true))
            ->when(isset($filters['status']), fn ($q) => $q->where('assignment_status', $filters['status']))
            ->when($organizationCode !== '', fn ($q) => $q->whereHas('organization', fn ($o) => $o->where('code', $organizationCode)))
            ->when($search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employee_number', $search)->orWhere('full_name', 'like', '%'.$search.'%')))
            ->orderByDesc('effective_from')
            ->orderBy('id');
        $page = $this->scope->apply($query, $request->user())->paginate(25)->withQueryString();

        return Inertia::render('Core/Assignments', [
            'assignments' => $page->getCollection()->map(fn (EmployeeAssignment $assignment): array => [
                'id' => $assignment->id,
                'status' => $assignment->assignment_status,
                'is_current' => (bool) $assignment->is_current,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'assignment_type' => $assignment->assignment_type,
                'reason_code' => $assignment->reason_code,
                'source_system' => $assignment->source_system,
                'employee' => $assignment->employee?->only(['id', 'employee_number', 'full_name', 'status']),
                'organization' => $assignment->organization?->only(['id', 'code', 'name_en', 'name_am']),
                'unit' => $assignment->unit?->only(['id', 'code', 'name_en', 'name_am']),
                'position' => $assignment->position === null ? null : [
                    'id' => $assignment->position->id,
                    'code' => $assignment->position->job_position_code,
                    'title_en' => $assignment->position->title_en,
                    'title_am' => $assignment->position->title_am,
                ],
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'filters' => ['search' => $search, 'organization' => $organizationCode, 'status' => $filters['status'] ?? '', 'history' => $withHistory],
            'statuses' => [EmployeeAssignment::ACTIVE, EmployeeAssignment::CLOSED],
        ]);
    }

    public function auditCenter(Request $request): Response
    {
        $since = now()->subDays(self::AUDIT_WINDOW_DAYS);
        $from = $since->toDateString();

        return Inertia::render('Core/AuditCenter', [
            'views' => collect(self::AUDIT_VIEWS)->map(fn (array $filter, string $key): array => [
                'key' => $key,
                'count' => $this->auditQuery($request->user())->where('created_at', '>=', $since)->where('action', 'like', $filter['event_type'].'%')->count(),
                'href' => route('audit-logs.index', [...$filter, 'from' => $from]),
            ])->values(),
            'windowDays' => self::AUDIT_WINDOW_DAYS,
        ]);
    }

    public function auditLogs(Request $request): Response
    {
        $search = trim($request->string('search')->toString());
        $eventType = trim($request->string('event_type')->toString());
        $requestId = trim($request->string('request_id')->toString());
        $principal = $request->string('principal')->toString();
        $from = $this->date($request->string('from')->toString());
        $to = $this->date($request->string('to')->toString());
        if ($from === null && $to === null) {
            $from = now()->subDays(self::AUDIT_WINDOW_DAYS)->startOfDay();
        }

        $page = $this->auditQuery($request->user())
            ->orderByDesc('created_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('subject_id', $search)->orWhere('subject_type', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn ($q) => $q->orWhere('actor_user_id', (int) $search))))
            ->when($eventType !== '', fn ($q) => $q->where('action', 'like', "{$eventType}%"))
            ->when($requestId !== '', fn ($q) => $q->where('request_id', $requestId))
            ->when($principal === 'person', fn ($q) => $q->whereNotNull('actor_user_id'))
            ->when($principal === 'system', fn ($q) => $q->whereNull('actor_user_id'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()))
            ->paginate(50)->withQueryString();
        $actors = User::query()->whereIn('id', $page->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => $page->getCollection()->map(fn (CoreAuditLog $log): array => [
                'id' => $log->id,
                'event_type' => $log->action,
                'actor_user_id' => $log->actor_user_id,
                'actor_name' => $log->actor_user_id ? $actors->get($log->actor_user_id) : null,
                'auditable_type' => $log->subject_type,
                'auditable_id' => $log->subject_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'filters' => [...$request->only('search', 'event_type', 'request_id', 'principal'), 'from' => $from?->toDateString(), 'to' => $to?->toDateString()],
        ]);
    }

    /** Audit rows the reader may see: their organizations' events; citywide rows stay with citywide readers. */
    private function auditQuery(User $user): Builder
    {
        $ids = $this->scope->organizationIds($user);

        return CoreAuditLog::query()->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids));
    }

    /**
     * @param  list<string>|null  $ids
     * @return array<string, int|string|null>
     */
    private function metricValues(User $user, ?array $ids, IntegrityChecks $checks): array
    {
        $inScope = fn (Builder $query, string $column = 'organization_id'): Builder => $ids === null ? $query : $query->whereIn($column, $ids);
        $placed = $inScope(EmployeeAssignment::query()->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE))->select('employee_id');
        $ofEmployees = fn (Builder $query, string $column = 'employee_id'): Builder => $ids === null ? $query : $query->whereIn($column, $placed);
        $activePositions = fn (): Builder => $inScope(Position::query()->where('is_active', true));
        $held = $activePositions()->whereHas('holders')->count();
        $now = now();

        return [
            'organizations_active' => $inScope(Organization::query()->where('status', Organization::ACTIVE), 'id')->count(),
            'units_active' => $inScope(OrganizationUnit::query()->where('status', 'active'))->count(),
            'hierarchy_version' => HierarchyVersion::current()?->version_name,
            'positions_active' => $activePositions()->count(),
            'positions_inactive' => $inScope(Position::query()->where('is_active', false))->count(),
            'positions_held' => $held,
            'positions_without_holder' => max(0, $activePositions()->count() - $held),
            'employees_active' => $ofEmployees(Employee::query()->where('status', Employee::ACTIVE), 'id')->count(),
            'assignments_current' => $inScope(EmployeeAssignment::query()->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE))->count(),
            // An unplaced employee belongs to no organization, so only a city-wide reader may count them (ADR 0009).
            'employees_unplaced' => $ids === null ? Employee::query()->where('status', Employee::ACTIVE)->whereNull('current_assignment_id')->count() : null,
            'cards_active' => $ofEmployees(IdCard::query()->where('status', IdCard::ACTIVE)->where('is_current', true))->count(),
            'cards_expiring' => $ofEmployees(IdCard::query()->where('status', IdCard::ACTIVE)->where('is_current', true)->whereBetween('expires_at', [$now, $now->copy()->addDays(self::EXPIRY_WINDOW_DAYS)]))->count(),
            'card_requests_open' => $ofEmployees(CardRequest::query()->where('status', CardRequest::SUBMITTED))->count(),
            'cards_suspended' => $ofEmployees(IdCard::query()->where('status', IdCard::SUSPENDED))->count(),
            'cards_revoked' => $ofEmployees(IdCard::query()->where('status', IdCard::REVOKED))->count(),
            'users_active' => User::query()->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('scope_organization_id', $ids))->count(),
            'audit_events_24h' => CoreAuditLog::query()->where('created_at', '>=', $now->copy()->subDay())->count(),
            // From the last scan (core:data-quality-scan), never the checks themselves on a page load (ADR 0011).
            'data_quality_findings' => $ids === null ? DB::table('data_quality_issues')->whereIn('status', ['open', 'investigating'])->count() : null,
        ] + $this->operationalMetrics($ids);
    }

    /**
     * The P4/P5 KPIs (ADR 0011). Workload counts are cached for a minute per scope;
     * security figures (emergency access, two-step sign-in, failed jobs) are always live.
     *
     * @param  list<string>|null  $ids
     * @return array<string, int|string|null>
     */
    private function operationalMetrics(?array $ids): array
    {
        $key = 'core.dashboard.'.($ids === null ? 'city' : sha1(implode(',', $ids)));
        $workload = Cache::remember($key, 60, function () use ($ids): array {
            $scoped = fn (\Illuminate\Database\Query\Builder $q, string $column) => $ids === null ? $q : $q->whereIn($column, $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids);
            $employees = fn (array $statuses) => $scoped(DB::table('employees')->join('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')
                ->when($statuses !== [], fn ($q) => $q->whereIn('employees.status', $statuses)), 'a.organization_id')->count();
            $today = now()->toDateString();
            $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';
            $default = app(CoreSettings::class)->int('workforce.default_seat_capacity');
            $seats = "(select coalesce(sum(approved_slots), 0) from position_establishments s where s.position_id = positions.id and s.status = 'approved' and s.deleted_at is null and s.effective_from <= '{$today}' and (s.effective_to is null or s.effective_to >= '{$today}'))";
            $capacity = "(case when {$seats} > 0 then {$seats} else {$default} end)";
            $holders = "(select count(*) from employee_assignments h where h.position_id = positions.id and h.is_current = {$true} and h.assignment_status = 'active')";
            $positions = $scoped(DB::table('positions')->where('positions.lifecycle_status', 'approved')->whereNull('positions.deleted_at'), 'positions.organization_id')
                ->selectRaw("coalesce(sum({$capacity}), 0) as seats, coalesce(sum({$holders}), 0) as held, sum(case when {$capacity} > {$holders} then 1 else 0 end) as vacant")->first();
            $placedActive = $scoped(DB::table('employees')->join('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')
                ->where('employees.status', Employee::ACTIVE)->where('a.assignment_status', EmployeeAssignment::ACTIVE), 'a.organization_id');
            $withCard = (clone $placedActive)->whereExists(fn ($q) => $q->selectRaw('1')->from('id_cards')->whereColumn('id_cards.employee_id', 'employees.id')
                ->where('id_cards.is_current', true)->whereIn('id_cards.status', IdCard::LIVE))->count();
            $active = $placedActive->count();
            $cards = fn (array $statuses) => $scoped(DB::table('id_cards')->join('employees', 'employees.id', '=', 'id_cards.employee_id')
                ->join('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')->whereIn('id_cards.status', $statuses), 'a.organization_id')->count();
            $issues = fn () => $scoped(DB::table('data_quality_issues')->whereIn('status', ['open', 'investigating']), 'organization_id');

            return [
                'organizations_total' => $scoped(DB::table('organizations')->whereNull('deleted_at'), 'id')->count(),
                'employees_total' => $employees([]),
                'employees_pending_verification' => $employees([Employee::SUBMITTED]),
                'employees_pending_approval' => $employees([Employee::VERIFIED]),
                'positions_vacant' => (int) ($positions->vacant ?? 0),
                'positions_utilization' => (int) ($positions->seats ?? 0) === 0 ? null : number_format(100 * (int) $positions->held / (int) $positions->seats, 1).'%',
                'card_coverage' => $active === 0 ? null : number_format(100 * $withCard / $active, 1).'%',
                'cards_lost_replaced' => $cards([IdCard::LOST, IdCard::DAMAGED, IdCard::REPLACED]),
                'card_approvals_pending' => $scoped(DB::table('card_requests')->join('employees', 'employees.id', '=', 'card_requests.employee_id')
                    ->join('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')->whereIn('card_requests.status', ['submitted', 'verified']), 'a.organization_id')->count(),
                'data_quality_critical' => $issues()->where('severity', 'critical')->count(),
                'data_quality_open' => $issues()->count(),
                'hierarchy_pending' => $ids === null ? DB::table('hierarchy_versions')->whereIn('status', ['review', 'approved'])->count() : null,
                'webhooks_failed' => $ids === null ? DB::table('webhook_deliveries')->where('status', 'failed')->count() : null,
                'api_errors_24h' => $ids === null ? DB::table('api_request_logs')->where('created_at', '>=', now()->subDay())->where('status', '>=', 500)->count() : null,
            ];
        });
        if ($ids !== null) {
            return $workload;
        }
        $privileged = User::query()->where('is_active', true)->whereIn('role', CorePermissions::rolesWith('core.write'));
        $withMfa = (clone $privileged)->where('two_factor_enabled', true)->whereNotNull('two_factor_confirmed_at')->count();
        $total = $privileged->count();

        return $workload + [
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'break_glass_active' => BreakGlassGrant::query()->inForce()->count(),
            'mfa_coverage' => $total === 0 ? null : number_format(100 * $withMfa / $total, 1).'% ('.$withMfa.'/'.$total.')',
        ];
    }

    /** @return list<array{event_type: string, actor_name: string|null, subject: string|null, created_at: string|null}> */
    private function recentActivity(User $user): array
    {
        if (! $user->can('audit.view')) {
            return [];
        }
        $rows = $this->auditQuery($user)
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function (Builder $q): void {
                foreach (self::ACTIVITY_PREFIXES as $prefix) {
                    $q->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->orderByDesc('created_at')->limit(8)->get();
        $names = User::query()->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (CoreAuditLog $log): array => [
            'event_type' => $log->action,
            'actor_name' => $log->actor_user_id === null ? null : $names->get($log->actor_user_id),
            'subject' => $log->subject_type,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values()->all();
    }

    private function domainLink(User $user, string $domain): ?string
    {
        foreach (self::DOMAIN_LINKS[$domain] ?? [] as $route => $permission) {
            if ($user->can($permission)) {
                return route($route);
            }
        }

        return null;
    }

    private function date(string $value): ?Carbon
    {
        if ($value === '' || ! Str::of($value)->isMatch('/^\d{4}-\d{2}-\d{2}/')) {
            return null;
        }

        return rescue(fn () => Carbon::parse($value), null, false);
    }
}
