<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
use App\Settings\CoreSettingsRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    ];

    private const DOMAIN_LINKS = [
        'organization' => ['organizations.index' => 'organizations.view', 'organization-units.index' => 'organization-units.viewAny'],
        'positions' => ['positions.index' => 'positions.viewAny'],
        'employees' => ['employees.index' => 'employees.view'],
        'credentials' => ['id-cards.index' => 'cards.view', 'card-requests.index' => 'id-cards.viewAny'],
        'governance' => ['users.index' => 'users.viewAny', 'audit-logs.index' => 'audit.view'],
        'quality' => [],
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

    /** Settings sections of the EUISIS settings page that the Core keeps (Core settings groups). */
    private const SETTINGS_SECTIONS = ['general', 'localization', 'appearance', 'security', 'id_cards'];

    /** Core-only groups, shown and saved with the General section. */
    private const GENERAL_EXTRAS = ['numbering', 'workforce', 'files', 'integration', 'reports'];

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

    public function settings(): Response
    {
        return Inertia::render('Core/Settings', [
            'sections' => collect(self::SETTINGS_SECTIONS)->map(fn (string $key): array => [
                'key' => $key,
                'href' => route('system-settings.index').'?tab='.$key,
            ])->values(),
        ]);
    }

    /** EUISIS SystemSettings/Index over the Core settings registry. */
    public function systemSettings(Request $request, CoreSettings $settings): Response
    {
        $definitions = CoreSettingsRegistry::definitions();
        $values = $settings->values();
        $field = fn (string $group, string $key, array $definition, int $order): array => [
            'key' => $key,
            'group' => $group,
            'type' => $definition['type'],
            'value' => $values[$group][$key],
            'is_encrypted' => false,
            'is_public' => $definition['is_public'],
            'configured' => true,
            'options' => $definition['options'],
            'validation_rules' => $definition['rules'],
            'description_en' => $definition['description_en'],
            'description_am' => $definition['description_am'],
            'label_en' => $definition['label_en'],
            'label_am' => $definition['label_am'],
            'sort_order' => $order,
            'default' => $definition['default'],
            'is_required' => in_array('required', $definition['rules'], true),
            'asset_url' => null,
        ];

        $groups = [];
        foreach (self::SETTINGS_SECTIONS as $group) {
            $fields = [];
            $order = 0;
            foreach ([$group, ...($group === 'general' ? self::GENERAL_EXTRAS : [])] as $source) {
                foreach ($definitions[$source] as $key => $definition) {
                    $fields[] = $field($source, $key, $definition, $order++);
                }
            }
            $groups[$group] = ['fields' => $fields, 'can_manage' => $request->user()->can('system-settings.update')];
        }
        $can = $request->user()->can('system-settings.update');

        return Inertia::render('SystemSettings/Index', [
            'settingGroups' => $groups,
            'emailInEffect' => null,
            'roles' => [],
            'can' => [
                'view' => true, 'update' => $can,
                'manageGeneral' => $can, 'manageLocalization' => $can, 'manageNotifications' => false, 'manageEmail' => false,
                'manageSms' => false, 'manageTelegram' => false, 'manageSecurity' => $can, 'manageAppearance' => $can,
                'manageIdCards' => $can, 'manageFieldWorkGps' => false, 'manageSituationRoom' => false, 'manageIntelligence' => false,
                'viewIdCardTemplates' => false, 'clearCache' => false,
            ],
        ]);
    }

    public function updateSettings(Request $request, CoreSettings $settings, string $section): RedirectResponse
    {
        abort_unless(in_array($section, self::SETTINGS_SECTIONS, true), 404);
        $definitions = CoreSettingsRegistry::definitions();
        foreach ([$section, ...($section === 'general' ? self::GENERAL_EXTRAS : [])] as $group) {
            $input = array_intersect_key($request->all(), $definitions[$group]);
            if ($input !== []) {
                $settings->update($group, $input, $request->user());
            }
        }

        return back()->with('flash', ['message' => __('Settings saved.'), 'type' => 'success']);
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
            'data_quality_findings' => $ids === null ? array_sum($checks->counts()) : null,
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
