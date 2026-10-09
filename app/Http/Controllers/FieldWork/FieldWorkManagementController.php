<?php

declare(strict_types=1);

namespace App\Http\Controllers\FieldWork;

use App\Enums\FieldWorkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\DecideFieldWorkRequest;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Services\FieldWork\EmployeeAvailabilityService;
use App\Services\FieldWork\FieldWorkPresenter;
use App\Services\FieldWork\FieldWorkQueryService;
use App\Services\FieldWork\FieldWorkService;
use App\Services\FieldWork\FieldWorkSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Field Work Management — supervisor and HR side.
 *
 * Every list starts from FieldWorkQueryService (team coverage and/or
 * organization scope); filters only narrow it. Single-record actions are
 * re-authorised against the record by FieldWorkRequestPolicy, and decisions
 * re-check the live supervisor identity inside FieldWorkService.
 */
class FieldWorkManagementController extends Controller
{
    public function __construct(
        private readonly FieldWorkQueryService $queries,
        private readonly FieldWorkPresenter $presenter,
        private readonly FieldWorkService $service,
        private readonly FieldWorkSettings $settings,
        private readonly EmployeeAvailabilityService $availability,
    ) {}

    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $preview = fn (Builder $query): array => $query->with(FieldWorkPresenter::SUMMARY_WITH)->withCount('participants')
            ->orderBy('expected_return_at')->limit(8)->get()
            ->map(fn (FieldWorkRequest $r): array => $this->presenter->summary($r))->all();

        return Inertia::render('FieldWork/Dashboard', [
            'figures' => $this->queries->dashboardFigures($user),
            'awaitingApproval' => $user->can('field_work.view_team') ? $preview($this->queries->approvalQueue($user)) : [],
            'attention' => $preview($this->queries->managed($user)->where(fn (Builder $q) => $q->overdue()->orWhere(fn (Builder $c) => $c->checkInMissing()))),
            'inField' => $preview($this->queries->managed($user)->where('status', FieldWorkStatus::InField->value)),
            'can' => $this->abilities($user),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        return $this->listPage($request, 'FieldWork/Requests', $this->queries->managed($user), 'field-work.requests.index');
    }

    public function approvals(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('field_work.approve') || $user->can('field_work.return') || $user->can('field_work.reject'), 403);

        return $this->listPage($request, 'FieldWork/Approvals', $this->queries->approvalQueue($user), 'field-work.pending', oldestFirst: true);
    }

    public function team(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('field_work.view_team'), 403);

        return $this->listPage($request, 'FieldWork/Team', $this->queries->team($user), 'field-work.team.index');
    }

    public function overdue(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $query = $this->queries->managed($user)->where(fn (Builder $q) => $q->overdue()->orWhere(fn (Builder $c) => $c->checkInMissing()));

        return $this->listPage($request, 'FieldWork/Overdue', $query, 'field-work.overdue.index', oldestFirst: true);
    }

    /** Who is on field work right now, within team coverage / organization scope. */
    public function availability(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $now = now();
        $page = $this->availability->teamAvailability($user, $now, $this->perPage($request));

        return Inertia::render('FieldWork/Availability', [
            'rows' => [
                'data' => collect($page->items())->map(fn (FieldWorkParticipant $p): array => [
                    'id' => $p->id,
                    'status' => $this->availability->statusOf($p, $now)->value,
                    'employee' => $this->presenter->employee($p->employee),
                    'organization_unit' => $p->organizationUnit ? ['name_en' => $p->organizationUnit->name_en, 'name_am' => $p->organizationUnit->name_am] : null,
                    'request' => ['id' => $p->request->id, 'reference_number' => $p->request->reference_number],
                    'destination' => $p->request->destinationOrganization
                        ? ['name_en' => $p->request->destinationOrganization->name_en, 'name_am' => $p->request->destinationOrganization->name_am]
                        : ['name_en' => $p->request->external_organization_name ?? $p->request->site_name ?? $p->request->destination_address, 'name_am' => null],
                    'expected_return_at' => $this->settings->local($p->request->expected_return_at),
                ])->all(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'per_page' => $page->perPage()],
                'links' => $page->linkCollection()->toArray(),
            ],
            'at' => $this->settings->local($now),
            'can' => $this->abilities($user),
        ]);
    }

    public function show(Request $request, FieldWorkRequest $fieldWorkRequest): Response
    {
        $user = $request->user();
        $this->authorize('view', $fieldWorkRequest);

        return Inertia::render('FieldWork/Show', [
            'fieldWork' => $this->presenter->detail($fieldWorkRequest, $user),
            'can' => [
                ...$this->abilities($user),
                'approve' => $user->can('approve', $fieldWorkRequest),
                'return' => $user->can('returnForCorrection', $fieldWorkRequest),
                'reject' => $user->can('reject', $fieldWorkRequest),
            ],
        ]);
    }

    public function approve(DecideFieldWorkRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->service->approve($request->user(), $fieldWorkRequest, $request->validated('reason'));

        return back()->with('success', __('field-work.flash.approved'));
    }

    public function returnForCorrection(DecideFieldWorkRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->service->returnForCorrection($request->user(), $fieldWorkRequest, (string) $request->validated('reason'));

        return back()->with('success', __('field-work.flash.returned'));
    }

    public function reject(DecideFieldWorkRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->service->reject($request->user(), $fieldWorkRequest, (string) $request->validated('reason'));

        return back()->with('success', __('field-work.flash.rejected'));
    }

    /** @param Builder<FieldWorkRequest> $query */
    private function listPage(Request $request, string $component, Builder $query, string $routeName, bool $oldestFirst = false): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $this->queries->applyFilters($query, $filters);

        $paginator = $query->with(FieldWorkPresenter::SUMMARY_WITH)
            ->withCount('participants')
            ->orderBy('starts_at', $oldestFirst ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render($component, [
            'requests' => $this->paginated($paginator),
            'filters' => $filters,
            'options' => $this->queries->filterOptions($user, $filters['organization_id'] ?? null),
            'routeName' => $routeName,
            'can' => $this->abilities($user),
        ]);
    }

    private function canManage(User $user): bool
    {
        return $user->can('field_work.view_team') || $user->can('field_work.view_org');
    }

    /** @return array<string, bool> which module pages to offer; each page re-checks */
    private function abilities(User $user): array
    {
        return [
            'dashboard' => $this->canManage($user),
            'requests' => $this->canManage($user),
            'approvals' => $user->can('field_work.approve') || $user->can('field_work.return') || $user->can('field_work.reject'),
            'team' => $user->can('field_work.view_team'),
            'availability' => $this->canManage($user),
            'overdue' => $this->canManage($user),
            'types' => $user->can('field_work.manage_types'),
            'oversight' => $user->can('field_work.view_org'),
        ];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (FieldWorkQueryService::FILTER_KEYS as $key) {
            $value = $request->query($key);
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = mb_substr(trim($value), 0, 100);
            }
        }

        return $filters;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 25);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    }

    /** @return array<string, mixed> */
    private function paginated(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (FieldWorkRequest $r): array => $this->presenter->summary($r))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'per_page' => $paginator->perPage(),
            ],
            'links' => $paginator->linkCollection()->toArray(),
        ];
    }
}
