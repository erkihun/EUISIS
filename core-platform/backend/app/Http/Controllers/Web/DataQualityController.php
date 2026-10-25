<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageDataQualityIssues;
use App\Http\Controllers\Controller;
use App\Jobs\ScanDataQualityJob;
use App\Models\DataQualityIssue;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\IntegrityChecks;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/DataQuality (ADR 0011): the issues the scan found, by tab (overview,
 * critical, open, assigned to me, resolved, check definitions) and filter,
 * inside the reader's organizations; an issue with no organization is seen
 * city-wide only. Each issue links to its record and offers the workflow
 * steps the reader may take.
 */
class DataQualityController extends Controller
{
    private const TABS = ['overview', 'critical', 'open', 'mine', 'resolved', 'definitions'];

    private const ROUTES = ['position' => 'positions.show', 'employee' => 'employees.show', 'card' => 'id-cards.show', 'organization' => 'organizations.show'];

    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'tab' => ['sometimes', Rule::in(self::TABS)],
            'organization' => ['nullable', 'uuid'],
            'severity' => ['nullable', Rule::in(IntegrityChecks::SEVERITIES)],
            'status' => ['nullable', Rule::in(DataQualityIssue::STATUSES)],
            'check' => ['nullable', Rule::in(array_keys(IntegrityChecks::SUBJECTS))],
            'entity' => ['nullable', Rule::in(array_keys(self::ROUTES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tab = $filters['tab'] ?? 'overview';
        /** @var User $user */
        $user = $request->user();
        $base = fn (): Builder => $this->visible(DataQualityIssue::query(), $user);

        $counts = $base()->toBase()->whereIn('status', DataQualityIssue::ACTIVE)->groupBy('check_code', 'severity')
            ->selectRaw('check_code, severity, count(*) as total')->get();
        $issues = null;
        if (! in_array($tab, ['overview', 'definitions'], true)) {
            $page = $base()->with('assignee:id,name')
                ->when($tab === 'critical', fn ($q) => $q->where('severity', 'critical')->whereIn('status', DataQualityIssue::ACTIVE))
                ->when($tab === 'open', fn ($q) => $q->whereIn('status', DataQualityIssue::ACTIVE))
                ->when($tab === 'mine', fn ($q) => $q->where('assigned_to', $user->getKey())->whereIn('status', DataQualityIssue::ACTIVE))
                ->when($tab === 'resolved', fn ($q) => $q->whereNotIn('status', DataQualityIssue::ACTIVE))
                ->when($filters['organization'] ?? null, fn ($q, string $id) => $q->where('organization_id', $id))
                ->when($filters['severity'] ?? null, fn ($q, string $v) => $q->where('severity', $v))
                ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
                ->when($filters['check'] ?? null, fn ($q, string $v) => $q->where('check_code', $v))
                ->when($filters['entity'] ?? null, fn ($q, string $v) => $q->where('entity_type', $v))
                ->when($filters['from'] ?? null, fn ($q, string $v) => $q->whereDate('detected_at', '>=', $v))
                ->when($filters['to'] ?? null, fn ($q, string $v) => $q->whereDate('detected_at', '<=', $v))
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")
                ->orderByDesc('last_detected_at')->paginate(25)->withQueryString();
            $organizations = Organization::query()->whereIn('id', collect($page->items())->pluck('organization_id')->filter()->unique())->get(['id', 'name_en', 'name_am'])->keyBy('id');
            $canManage = $user->can('data_quality.manage');
            $canAccept = $user->can('data_quality.accept_risk');
            $issues = [
                'data' => collect($page->items())->map(fn (DataQualityIssue $issue): array => [
                    'id' => $issue->id, 'check_code' => $issue->check_code, 'severity' => $issue->severity, 'status' => $issue->status,
                    'summary' => $issue->summary, 'entity_type' => $issue->entity_type, 'entity_id' => $issue->entity_id,
                    'entity_url' => isset(self::ROUTES[$issue->entity_type]) ? route(self::ROUTES[$issue->entity_type], $issue->entity_id) : null,
                    'organization' => $organizations->get($issue->organization_id)?->only(['name_en', 'name_am']),
                    'detected_at' => $issue->detected_at?->toIso8601String(), 'last_detected_at' => $issue->last_detected_at?->toIso8601String(),
                    'times_reopened' => $issue->times_reopened, 'assignee' => $issue->assignee?->name,
                    'resolution' => $issue->resolution, 'auto_resolved' => $issue->auto_resolved,
                    'resolved_at' => ($issue->resolved_at ?? $issue->accepted_risk_at)?->toIso8601String(),
                    'steps' => in_array($issue->status, DataQualityIssue::ACTIVE, true) ? array_values(array_filter([
                        $canManage && $issue->status === DataQualityIssue::OPEN ? 'investigate' : null,
                        $canManage ? 'resolve' : null, $canManage ? 'false_positive' : null, $canAccept ? 'accept_risk' : null,
                    ])) : [],
                    'step_url' => route('core.data-quality.step', ['issue' => $issue->id, 'step' => '__step__']),
                ])->values(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                    'from' => $page->firstItem(), 'to' => $page->lastItem()],
            ];
        }
        $ids = $this->scope->organizationIds($user);

        return Inertia::render('Core/DataQuality', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'filters' => array_merge(array_fill_keys(['organization', 'severity', 'status', 'check', 'entity', 'from', 'to'], ''), array_filter($filters, fn ($v, $k) => $k !== 'tab', ARRAY_FILTER_USE_BOTH)),
            'summary' => [
                'by_severity' => collect(IntegrityChecks::SEVERITIES)->mapWithKeys(fn (string $s): array => [$s => (int) $counts->where('severity', $s)->sum('total')]),
                'open' => (int) $counts->sum('total'),
                'mine' => $base()->where('assigned_to', $user->getKey())->whereIn('status', DataQualityIssue::ACTIVE)->count(),
            ],
            'checks' => collect(IntegrityChecks::SUBJECTS)->map(fn (array $subject, string $code): array => [
                'code' => $code, 'kind' => $subject[1], 'severity' => IntegrityChecks::SEVERITY[$code] ?? 'medium', 'summary' => IntegrityChecks::SUMMARIES[$code] ?? '',
                'open' => (int) $counts->where('check_code', $code)->sum('total'),
            ])->values(),
            'issues' => $issues,
            'options' => [
                'severities' => IntegrityChecks::SEVERITIES, 'statuses' => DataQualityIssue::STATUSES, 'entities' => array_keys(self::ROUTES),
                'organizations' => Organization::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am']),
            ],
            'scan_url' => $user->can('data_quality.manage') && $user->isCityWide() ? route('core.data-quality.scan') : null,
            'url' => route('core.data-quality'),
        ]);
    }

    public function step(Request $request, DataQualityIssue $issue, string $step, ManageDataQualityIssues $issues): RedirectResponse
    {
        abort_unless($issues->visible($issue, $request->user()), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'assigned_to' => ['nullable', 'integer']]);
        $issues->step($issue, $step, $data['note'] ?? null, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, $request->user());

        return back()->with('success', __('oversight.data_quality_updated'));
    }

    /** Runs the scan now, in the background. */
    public function scan(Request $request, AuditWriter $audit): RedirectResponse
    {
        abort_unless($request->user()->can('data_quality.manage') && $request->user()->isCityWide(), 403);
        ScanDataQualityJob::dispatch();
        $audit->writeFor('data_quality.scan_requested', $request->user(), 'DataQualityCheck', 'all', null);

        return back()->with('success', __('oversight.data_quality_scan_started'));
    }

    /** @param Builder<DataQualityIssue> $query */
    private function visible(Builder $query, User $user): Builder
    {
        $ids = $this->scope->organizationIds($user);

        return $ids === null ? $query : $query->whereIn('organization_id', $ids);
    }
}
