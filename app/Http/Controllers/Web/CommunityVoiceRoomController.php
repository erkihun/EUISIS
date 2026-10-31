<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Jobs\ExportSituationRoomReport;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\CommunityVoiceRoomService;
use App\Services\SituationRoom\EmployeeTargetedQuery;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationRoomFilters;
use App\Services\SituationRoom\SituationRoomMetrics as M;
use App\Services\SituationRoom\SituationRoomScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Contracts\FeedbackReportExports;
use Modules\Feedback\Contracts\FeedbackSettings;
use Modules\Feedback\Contracts\FeedbackVisibility;
use Modules\Feedback\Contracts\ReadModels\FeedbackReportExportRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Executive Situation Room (docs/executive-situation-room.md).
 *
 * Every action checks the permission and the institution server-side: an
 * institution outside the user's scope is a 403 whatever the URL says. All
 * figures are aggregates computed in SQL within that scope; submission text
 * appears only on the employee drill-down and only with situation_room.view_detail.
 */
class CommunityVoiceRoomController extends Controller
{
    public function __construct(
        private readonly CommunityVoiceRoomService $room,
        private readonly OrganizationScopeService $scopes,
        private readonly FeedbackVisibility $visibility,
        private readonly EmployeeTargetedQuery $employees,
        private readonly WriteAuditLogAction $audit,
        private readonly FeedbackSettings $settings,
        private readonly FeedbackReportExports $exports,
    ) {}

    public function index(Request $request): Response
    {
        return $this->overview($request, $request->query('organization'));
    }

    public function organization(Request $request, Organization $organization): Response
    {
        return $this->overview($request, (string) $organization->getKey());
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $this->authorizeView();
        $scope = $this->scope($user, $request->input('organization'));
        $this->room->forget($scope, SituationRoomFilters::fromInput($request->all()));

        return back()->with('success', __('situation-room.refreshed'));
    }

    /** Employee-targeted counts inside the institution in view. */
    public function targetedEmployees(Request $request): Response
    {
        $user = $this->authorizeEmployeeTargeted();
        $scope = $this->scope($user, $request->query('organization'));
        $filters = SituationRoomFilters::fromInput($request->query());
        $page = max(1, (int) $request->query('page', 1));
        $sort = $request->query('sort') === 'total' ? 'total' : 'latest';

        return Inertia::render('SituationRoom/Employees', [
            'scope' => $this->scopeProps($scope),
            'filters' => $filters->toArray(),
            'filterOptions' => $this->filterOptions(),
            'employees' => $this->room->employeeSummary($scope, $filters, $page, $sort),
        ]);
    }

    /** One employee's submissions recorded inside the institution in view. */
    public function targetedEmployee(Request $request, Employee $employee): Response
    {
        $user = $this->authorizeEmployeeTargeted();
        $scope = $this->scope($user, $request->query('organization'));
        $filters = SituationRoomFilters::fromInput($request->query());
        $withNarrative = $user->can(M::PERMISSION_DETAIL);

        $result = $this->employees->submissions($scope, $filters, $employee, $withNarrative, max(1, (int) $request->query('page', 1)));
        // Nothing recorded about this employee in the leader's scope: the name is not theirs to see.
        abort_if($result['total'] === 0 && ! $this->employeeInScope($user, $employee), 404);

        if ($withNarrative && $result['rows'] !== []) {
            $this->audit->execute(
                eventType: AuditEventType::SituationRoomDetailViewed,
                actor: $user,
                auditable: $employee,
                organizationId: $scope->node?->getKey(),
                newValues: ['employee_id' => (string) $employee->getKey(), 'submissions' => array_column($result['rows'], 'id'), 'filters' => $filters->toArray()],
                reason: 'Situation Room employee drill-down with submission text',
                request: $request,
            );
        }

        return Inertia::render('SituationRoom/Employee', [
            'scope' => $this->scopeProps($scope),
            'filters' => $filters->toArray(),
            'employee' => ['id' => (string) $employee->getKey(), 'name_en' => $employee->fullNameEn(), 'name_am' => $employee->fullNameAm()],
            'submissions' => $result,
            'can' => ['viewDetail' => $withNarrative],
        ]);
    }

    /** Institutions the user may put in view, for the scope selector. */
    public function organizationOptions(Request $request): JsonResponse
    {
        $user = $this->authorizeView();
        $term = trim((string) $request->query('q', ''));

        $query = Organization::query()->where('status', 'active');
        if (! $this->scopes->isUnrestricted($user)) {
            $query->whereIn('id', $this->scopes->accessibleOrganizationIds($user)->all());
        }
        if ($term !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name_en) LIKE ?', [$like])->orWhere('name_am', 'like', '%'.$term.'%')->orWhereRaw('LOWER(code) LIKE ?', [$like]));
        }

        return response()->json($query->orderBy('name_en')->limit(20)->get(['id', 'code', 'name_en', 'name_am'])
            ->map(fn (Organization $o): array => ['id' => (string) $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am]));
    }

    public function export(Request $request): RedirectResponse
    {
        $user = $this->authorizeView();
        abort_unless($user->can(M::PERMISSION_EXPORT), 403);
        $data = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]]);
        $scope = $this->scope($user, $request->input('organization'));
        $filters = SituationRoomFilters::fromInput($request->all());

        $exportId = $this->exports->queue(
            report: ExportSituationRoomReport::REPORT,
            format: $data['format'],
            filters: $filters->toArray() + ['locale' => app()->getLocale()],
            requestedBy: $user->getKey(),
            organizationId: $scope->node?->getKey(),
        );
        ExportSituationRoomReport::dispatch($exportId);

        return back()->with('success', __('situation-room.export_queued'));
    }

    public function download(FeedbackReportExportRecord $export): StreamedResponse
    {
        abort_unless($export->report === ExportSituationRoomReport::REPORT, 404);
        $this->authorize('download', $export);
        abort_unless($export->isReady() && Storage::disk($export->disk)->exists($export->path), 404);

        $mime = $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::disk($export->disk)->download($export->path, $export->filename ?? ($export->id.'.'.$export->format), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function overview(Request $request, mixed $organizationId): Response
    {
        $user = $this->authorizeView();
        $scope = $this->scope($user, $organizationId);
        $filters = SituationRoomFilters::fromInput($request->query());

        return Inertia::render('SituationRoom/CommunityVoice', [
            'scope' => $this->scopeProps($scope),
            'filters' => $filters->toArray(),
            'filterOptions' => $this->filterOptions(),
            // KPIs and trend first; the rest streams in after the page is painted.
            'headline' => $this->room->headline($scope, $filters),
            'comparison' => Inertia::defer(fn (): array => $this->room->comparison($scope, $filters), 'comparison'),
            'breakdown' => Inertia::defer(fn (): array => $this->room->breakdown($scope, $filters), 'details'),
            'supporting' => Inertia::defer(fn (): array => $this->room->supporting($scope, $filters), 'details'),
            'exports' => $user->can(M::PERMISSION_EXPORT) ? $this->recentExports($user) : [],
            'rules' => $this->settings->situationRoomRules(),
            'can' => [
                'employeeTargeted' => $this->canSeeEmployeeTargeted($user),
                'viewDetail' => $user->can(M::PERMISSION_DETAIL),
                'export' => $user->can(M::PERMISSION_EXPORT),
                'wall' => $user->can(M::PERMISSION_WALL),
                'manageActions' => $user->can(M::PERMISSION_ACTIONS),
            ],
        ]);
    }

    /** The room's grant and, since this view is community voice, the public-voice grant too. */
    private function authorizeView(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user->can(M::PERMISSION_VIEW) && $user->can(SituationPermissions::PUBLIC_VOICE), 403);

        return $user;
    }

    private function authorizeEmployeeTargeted(): User
    {
        $user = $this->authorizeView();
        abort_unless($this->canSeeEmployeeTargeted($user), 403);

        return $user;
    }

    private function canSeeEmployeeTargeted(User $user): bool
    {
        return $user->can(M::PERMISSION_EMPLOYEE_TARGETED) && $user->can('service_feedback.view_employee_target');
    }

    private function scope(User $user, mixed $organizationId): SituationRoomScope
    {
        $id = is_string($organizationId) && preg_match('/^[0-9a-f-]{36}$/i', $organizationId) === 1 ? $organizationId : null;
        abort_if(is_string($organizationId) && $organizationId !== '' && $id === null, 404);

        try {
            return SituationRoomScope::for($user, $id, $this->scopes, $this->visibility);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    private function employeeInScope(User $user, Employee $employee): bool
    {
        return $this->scopes->isUnrestricted($user) || $this->scopes->canAccessEmployee($user, $employee);
    }

    /** @return array<string, mixed> */
    private function scopeProps(SituationRoomScope $scope): array
    {
        return [
            'node' => $scope->node ? ['id' => (string) $scope->node->getKey(), 'code' => $scope->node->code, 'name_en' => $scope->node->name_en, 'name_am' => $scope->node->name_am] : null,
            'breadcrumb' => $scope->breadcrumb(),
            'unrestricted' => $scope->isUnrestricted(),
            'visibleTargets' => $scope->visibleTargets,
            'hierarchyPublished' => $scope->versionId !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'periods' => M::PERIODS,
            'types' => M::TYPES,
            'targets' => M::TARGETS,
            'statuses' => array_keys(M::STATUS_BUCKETS),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentExports(User $user): array
    {
        return FeedbackReportExportRecord::query()
            ->where('requested_by', $user->getKey())
            ->where('report', ExportSituationRoomReport::REPORT)
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (FeedbackReportExportRecord $export): array => [
                'id' => (string) $export->id,
                'format' => $export->format,
                'status' => $export->status,
                'filename' => $export->filename,
                'row_count' => $export->row_count,
                'created_at' => $export->created_at?->toIso8601String(),
            ])->all();
    }
}
