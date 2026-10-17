<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\PositionService;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackStatusHistory;
use App\Services\Calendar\LocalizedDateService;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackMetrics;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use App\Services\ServiceFeedback\ServiceFeedbackQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administrative review of client service feedback.
 *
 * Every read goes through ServiceFeedbackQueryService::scopedQuery(), so an
 * Organizational Admin's list, dashboard and exports are all confined to their
 * own organizations by the same rule. Per-record actions additionally run the
 * policy, which re-checks scope — the list filter alone is not treated as
 * sufficient authorisation for a mutation.
 */
class ServiceFeedbackController extends Controller
{
    private const FILTER_KEYS = [
        'organization_id',
        'organization_unit_id',
        'employee_id',
        // Matches the key the query service filters on and the one the
        // service-type dropdown submits; `position_service_id` is the column,
        // not the request key.
        'service_type_id',
        'service_no',
        'rating',
        'status',
        'date_from',
        'date_to',
        // Free text over comment, client name and employee.
        'q',
        // Who the feedback is about: an employee (their QR) or an institution (its QR).
        'target_type',
        'category_id',
    ];

    public function __construct(
        private readonly ServiceFeedbackQueryService $feedbackQuery,
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly PublicFeedbackVisibility $visibility,
        private readonly PublicFeedbackMetrics $metrics,
        private readonly PublicFeedbackWorkflow $workflow,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeServiceFeedback::class);

        $user = Auth::user();
        $filters = $request->only(self::FILTER_KEYS);

        $base = $this->feedbackQuery->applyFilters(
            $this->feedbackQuery->scopedQuery($user),
            $filters,
            $user,
        );

        return Inertia::render('ServiceFeedback/Dashboard', [
            // Brief §81: every public submission type, within the same scope and period.
            'submissions' => $this->metrics->overview($this->metrics->query($user, $this->metrics->filters(array_intersect_key($filters, array_flip(['organization_id', 'date_from', 'date_to']))))),
            'summary' => $this->feedbackQuery->summary($base),
            'ratingDistribution' => $this->feedbackQuery->ratingDistribution($base),
            'byOrganization' => $this->feedbackQuery->byOrganization($base),
            'byEmployee' => $this->feedbackQuery->byEmployee($base),
            'byServiceType' => $this->feedbackQuery->byServiceType($base),
            'recentComments' => $this->feedbackQuery->recentComments($base)
                ->map(fn (EmployeeServiceFeedback $item): array => $this->summarise($item)),
            'attention' => $this->feedbackQuery->needsAttention($base)
                ->map(fn (EmployeeServiceFeedback $item): array => $this->summarise($item)),
            'oldestPendingAt' => $this->feedbackQuery->oldestPendingAt($base),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($user),
        ]);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeServiceFeedback::class);

        $user = Auth::user();
        $filters = $request->only(self::FILTER_KEYS);

        $feedback = $this->feedbackQuery
            ->applyFilters($this->feedbackQuery->scopedQuery($user), $filters, $user)
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
                'position:id,title_en,title_am',
                'positionService:id,service_no,name_en,name_am',
                'organizationTarget:id,code,name_en,name_am',
                'category:id,name_en,name_am',
            ])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EmployeeServiceFeedback $item): array => $this->summarise($item));

        // Tab counts ignore the status filter, so each tab shows what choosing it would list.
        $statusCounts = $this->feedbackQuery->statusCounts(
            $this->feedbackQuery->applyFilters(
                $this->feedbackQuery->scopedQuery($user),
                array_diff_key($filters, ['status' => true]),
                $user,
            ),
        );

        return Inertia::render('ServiceFeedback/Index', [
            'feedback' => $feedback,
            'statusCounts' => $statusCounts,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($user),
            'statuses' => array_column(ServiceFeedbackStatus::cases(), 'value'),
            'can' => $this->abilities(),
        ]);
    }

    public function show(Request $request, EmployeeServiceFeedback $serviceFeedback): Response
    {
        abort_unless($serviceFeedback->type === ServiceFeedbackType::Feedback, 404);
        // Null-safe: an administrator without an employee record is not "the target" of an institution rating.
        abort_if(Auth::user()?->employee_id !== null && Auth::user()?->employee_id === $serviceFeedback->employee_id, 403);
        $this->authorize('view', $serviceFeedback);

        // Who sent it is a separate grant, and each opening of it is recorded.
        if ($this->visibility->canSeeIdentity(Auth::user()) && $this->visibility->hasIdentity($serviceFeedback)) {
            $this->writeAuditLogAction->execute(
                eventType: AuditEventType::PublicSubmitterIdentityViewed,
                actor: Auth::user(),
                auditable: $serviceFeedback,
                organizationId: $serviceFeedback->organization_id,
                reason: 'Submitter identity viewed on public feedback',
                request: $request,
            );
        }

        $serviceFeedback->load([
            'employee:id,full_name,employee_number',
            'organization:id,name_en,name_am',
            'organizationUnit:id,name_en,name_am',
            'position:id,title_en,title_am',
            'positionService:id,service_no,name_en,name_am',
            'organizationTarget:id,code,name_en,name_am,logo_path',
            'category:id,name_en,name_am',
            'reviewer:id,name',
            'statusHistory.actor:id,name',
        ]);

        return Inertia::render('ServiceFeedback/Show', [
            'feedback' => array_merge($this->summarise($serviceFeedback), [
                'review_note' => $serviceFeedback->review_note,
                'reviewed_at' => $serviceFeedback->reviewed_at?->toIso8601String(),
                'reviewed_by' => $serviceFeedback->reviewer?->name,
                'target_logo_url' => $serviceFeedback->organizationTarget?->logo_url,
            ]),
            // Every recorded change of state, oldest first: submitted, reviewed, resolved, closed, hidden, restored.
            'history' => $serviceFeedback->statusHistory->map(fn (PublicFeedbackStatusHistory $row): array => [
                'id' => $row->id,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'actor' => $row->actor?->name,
                'reason' => $row->reason,
                'transitioned_at' => $row->transitioned_at?->toIso8601String(),
            ])->values()->all(),
            // Only the moves the workflow allows from here, and only for a reviewer.
            'allowedTransitions' => Auth::user()->can('review', $serviceFeedback)
                ? array_values(array_map(fn (ServiceFeedbackStatus $to): string => $to->value, array_filter(
                    $this->workflow->allowedFrom(ServiceFeedbackType::Feedback, $serviceFeedback->status),
                    fn (ServiceFeedbackStatus $to): bool => $to !== ServiceFeedbackStatus::Closed || Auth::user()->can('transition', [$serviceFeedback, $to]),
                )))
                : [],
            // Organization form: the service received and the questionnaire answers (null otherwise).
            'organizationForm' => app(PublicFeedbackQuestionnaire::class)->presentForStaff($serviceFeedback, app()->getLocale()),
            'can' => $this->abilities($serviceFeedback),
        ]);
    }

    /**
     * Move a Feedback along its workflow: pending → reviewed | resolved,
     * reviewed → resolved | closed, resolved → closed. The workflow refuses
     * any other move, stamps the timestamps the reports read and appends a
     * history row; the review note is kept on the record and in that row.
     */
    public function review(Request $request, EmployeeServiceFeedback $serviceFeedback): RedirectResponse
    {
        abort_unless($serviceFeedback->type === ServiceFeedbackType::Feedback, 404);
        // Null-safe: an administrator without an employee record is not "the target" of an institution rating.
        abort_if(Auth::user()?->employee_id !== null && Auth::user()?->employee_id === $serviceFeedback->employee_id, 403);
        $this->authorize('review', $serviceFeedback);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:reviewed,resolved,closed'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = ServiceFeedbackStatus::from($data['status']);
        // Reviewing and resolving are the Feedback review grant; closing is the workflow's own close grant.
        if ($status === ServiceFeedbackStatus::Closed) {
            $this->authorize('transition', [$serviceFeedback, $status]);
        }
        $note = isset($data['review_note']) && trim((string) $data['review_note']) !== '' ? trim((string) $data['review_note']) : null;

        if (! $this->workflow->canTransition($serviceFeedback, $status)) {
            throw ValidationException::withMessages(['status' => __('service-feedback.transition_not_allowed')]);
        }

        DB::transaction(function () use ($serviceFeedback, $status, $note, $request): void {
            if ($note !== null) {
                $serviceFeedback->forceFill(['review_note' => $note])->save();
            }

            $this->workflow->transition($serviceFeedback, $status, Auth::user(), $note, $request);

            if ($status !== ServiceFeedbackStatus::Closed) {
                $this->writeAuditLogAction->execute(
                    eventType: $status === ServiceFeedbackStatus::Resolved
                        ? AuditEventType::ServiceFeedbackResolved
                        : AuditEventType::ServiceFeedbackReviewed,
                    actor: Auth::user(),
                    auditable: $serviceFeedback,
                    organizationId: $serviceFeedback->organization_id,
                    reason: 'Client feedback marked '.$status->value,
                    request: $request,
                );
            }
        });

        return back()->with('flash', ['message' => __('Feedback updated.'), 'type' => 'success']);
    }

    /**
     * Suppress or restore a comment.
     *
     * Hiding is reversible and never deletes: the row stays auditable, it
     * simply stops appearing in employee-facing views and the comment feed.
     */
    public function hide(Request $request, EmployeeServiceFeedback $serviceFeedback): RedirectResponse
    {
        abort_unless($serviceFeedback->type === ServiceFeedbackType::Feedback, 404);
        // Null-safe: an administrator without an employee record is not "the target" of an institution rating.
        abort_if(Auth::user()?->employee_id !== null && Auth::user()?->employee_id === $serviceFeedback->employee_id, 403);
        $this->authorize('hide', $serviceFeedback);

        $hiding = $serviceFeedback->status !== ServiceFeedbackStatus::Hidden;
        $from = $serviceFeedback->status;
        // Restoring returns the entry to where it stood before it was hidden, never silently to "pending".
        $to = $hiding ? ServiceFeedbackStatus::Hidden : $this->statusBeforeHidden($serviceFeedback);

        DB::transaction(function () use ($serviceFeedback, $from, $to): void {
            $serviceFeedback->forceFill([
                'status' => $to,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ])->save();
            // Moderation sits outside the workflow, but every change of state is still in the history.
            $this->workflow->record($serviceFeedback, $from, $to, Auth::user());
        });

        $this->writeAuditLogAction->execute(
            eventType: $hiding ? AuditEventType::ServiceFeedbackHidden : AuditEventType::ServiceFeedbackUnhidden,
            actor: Auth::user(),
            auditable: $serviceFeedback,
            organizationId: $serviceFeedback->organization_id,
            reason: $hiding ? 'Client feedback hidden' : 'Client feedback restored',
            request: $request,
        );

        return back()->with('flash', ['message' => __('Feedback updated.'), 'type' => 'success']);
    }

    /** The status recorded just before the latest hide; pending for an entry hidden before history existed. */
    private function statusBeforeHidden(EmployeeServiceFeedback $feedback): ServiceFeedbackStatus
    {
        $previous = PublicFeedbackStatusHistory::query()
            ->where('submission_id', $feedback->getKey())
            ->where('to_status', ServiceFeedbackStatus::Hidden->value)
            ->latest('transitioned_at')
            ->latest('id')
            ->value('from_status');

        $status = is_string($previous) ? ServiceFeedbackStatus::tryFrom($previous) : null;

        return $status === null || $status === ServiceFeedbackStatus::Hidden ? ServiceFeedbackStatus::Pending : $status;
    }

    public function destroy(Request $request, EmployeeServiceFeedback $serviceFeedback): RedirectResponse
    {
        abort_unless($serviceFeedback->type === ServiceFeedbackType::Feedback, 404);
        // Null-safe: an administrator without an employee record is not "the target" of an institution rating.
        abort_if(Auth::user()?->employee_id !== null && Auth::user()?->employee_id === $serviceFeedback->employee_id, 403);
        $this->authorize('delete', $serviceFeedback);

        // Audited before deletion so the record's details survive it.
        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackDeleted,
            actor: Auth::user(),
            auditable: $serviceFeedback,
            organizationId: $serviceFeedback->organization_id,
            oldValues: [
                'rating' => $serviceFeedback->rating,
                'position_service_id' => $serviceFeedback->position_service_id,
                'employee_id' => $serviceFeedback->employee_id,
            ],
            reason: 'Client feedback deleted',
            request: $request,
        );

        $serviceFeedback->delete();

        return to_route('service-feedback.admin.index')
            ->with('flash', ['message' => __('Feedback deleted.'), 'type' => 'success']);
    }

    /** Reports screen: performance rankings and the low-rating watchlist. */
    public function reports(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeServiceFeedback::class);

        $user = Auth::user();
        $filters = $request->only(self::FILTER_KEYS);

        $base = $this->feedbackQuery->applyFilters(
            $this->feedbackQuery->scopedQuery($user),
            $filters,
            $user,
        );

        $lowRated = (clone $base)
            ->lowRated()
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'positionService:id,service_no,name_en,name_am',
            ])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (EmployeeServiceFeedback $item): array => $this->summarise($item));

        return Inertia::render('ServiceFeedback/Reports', [
            'summary' => $this->feedbackQuery->summary($base),
            'byEmployee' => $this->feedbackQuery->employeePerformance($base),
            'byOrganization' => $this->feedbackQuery->byOrganization($base, 50),
            'byServiceType' => $this->feedbackQuery->byServiceType($base, 50),
            'lowRated' => $lowRated,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($user),
            'statuses' => array_column(ServiceFeedbackStatus::cases(), 'value'),
            'can' => ['export' => Auth::user()->can('export', EmployeeServiceFeedback::class)],
        ]);
    }

    /**
     * Stream the filtered feedback as CSV.
     *
     * Streamed rather than built in memory so a large date range cannot
     * exhaust the request's memory limit.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', EmployeeServiceFeedback::class);

        $user = Auth::user();
        $filters = $request->only(self::FILTER_KEYS);

        $query = $this->feedbackQuery
            ->applyFilters($this->feedbackQuery->scopedQuery($user), $filters, $user)
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
                'positionService:id,service_no,name_en,name_am',
                'organizationTarget:id,name_en,name_am',
                'category:id,name_en,name_am',
            ])
            ->latest('created_at');

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackExported,
            actor: $user,
            reason: 'Client feedback exported to CSV',
            request: $request,
        );

        $filename = 'service-feedback-'.now()->format('Ymd-His').'.csv';

        $showIdentity = $this->visibility->canSeeIdentity($user);
        // English: English headers and values, Gregorian dates. Amharic: Amharic, Ethiopian dates.
        $locale = app()->getLocale();
        $dates = app(LocalizedDateService::class);
        $name = fn (?object $model): ?string => $model === null ? null
            : ($locale === 'am' && filled($model->name_am ?? null) ? $model->name_am : $model->name_en);
        $columns = ['submitted_at', 'target_type', 'target', 'employee', 'employee_number', 'organization', 'unit', 'service', 'category', 'rating', 'status', 'comment', 'client_name', 'client_contact'];

        return response()->streamDownload(function () use ($query, $showIdentity, $locale, $dates, $name, $columns): void {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens the Amharic columns as UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, csv_safe_row(array_map(fn (string $column): string => (string) __('service-feedback.feedback_export_columns.'.$column), $columns)));

            $query->chunk(500, function ($rows) use ($handle, $showIdentity, $locale, $dates, $name): void {
                foreach ($rows as $item) {
                    $organizationTarget = $item->target_type === PublicFeedbackTargetType::Organization;
                    fputcsv($handle, csv_safe_row([
                        $dates->displayDateTime($item->submitted_at ?? $item->created_at, $locale),
                        (string) __('service-feedback.export_values.target_types.'.($organizationTarget ? 'organization' : 'employee')),
                        $organizationTarget ? ($name($item->organizationTarget) ?? $item->target_name_snapshot) : $item->employee?->full_name,
                        $item->employee?->full_name,
                        $item->employee?->employee_number,
                        $name($item->organization),
                        $name($item->organizationUnit),
                        $item->service_name_snapshot ?? $name($item->positionService) ?? $item->service_text,
                        $name($item->category),
                        $item->rating,
                        (string) __('service-feedback.export_values.statuses.'.$item->status->value),
                        $item->comment,
                        $showIdentity ? $item->client_name : null,
                        $showIdentity ? $item->client_contact : null,
                    ]));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Shape a record for the browser.
     *
     * Note what is absent: `ip_address` and `user_agent` never leave the
     * server. They exist for abuse investigation through the audit log, not
     * for display to administrators.
     *
     * @return array<string, mixed>
     */
    private function summarise(EmployeeServiceFeedback $item): array
    {
        return [
            'id' => $item->id,
            'rating' => $item->rating,
            'comment' => $item->comment,
            'status' => $item->status->value,
            // Brief §36: the submitter is a separate grant.
            ...$this->visibility->identity(Auth::user(), $item),
            'created_at' => $item->created_at?->toIso8601String(),
            'employee' => $item->employee === null ? null : [
                'id' => $item->employee->id,
                'name' => $item->employee->full_name,
                'employee_number' => $item->employee->employee_number,
            ],
            'organization' => $this->namePair($item->organization, 'name'),
            'organization_unit' => $this->namePair($item->organizationUnit, 'name'),
            'position' => $this->namePair($item->position, 'title'),
            'service_no' => $item->service_no_snapshot,
            'service_type' => $this->namePair($item->positionService, 'name'),
            // The service as recorded at submission: the employee's position service, or the institution's
            // catalog service (or the visitor's own words) on the organization form.
            'service_name' => $item->service_name_snapshot ?? $item->service_text,
            'target_type' => $item->target_type?->value ?? 'employee',
            // An institution target: its name (and code, an internal identifier staff use), never its database id.
            'target_organization' => $item->organizationTarget === null ? null : [
                'en' => $item->organizationTarget->name_en,
                'am' => $item->organizationTarget->name_am,
                'code' => $item->organizationTarget->code,
            ],
            'category' => $this->namePair($item->category, 'name'),
            'identity_mode' => $item->submitter_identity_mode?->value,
        ];
    }

    /** @return array{en: string|null, am: string|null}|null */
    private function namePair(mixed $model, string $field): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'en' => $model->{$field.'_en'} ?? null,
            'am' => $model->{$field.'_am'} ?? null,
        ];
    }

    /**
     * Organizations and service types offered in the filter dropdowns.
     *
     * The organization list is scoped too — otherwise the filter would leak
     * the names of organizations the user cannot otherwise see.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(mixed $user): array
    {
        $scope = app(OrganizationScopeService::class);

        $organizations = Organization::query()
            ->where('status', 'active')
            ->when(
                ! $scope->isUnrestricted($user),
                fn ($query) => $query->whereIn('id', $scope->accessibleOrganizationIds($user)->all()),
            )
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am']);

        return [
            'organizations' => $organizations,
            'serviceTypes' => PositionService::query()
                ->where('is_active', true)
                ->when(
                    ! $scope->isUnrestricted($user),
                    fn ($query) => $query->whereIn('organization_id', $scope->accessibleOrganizationIds($user)->all()),
                )
                ->orderBy('service_no')
                ->get(['id', 'service_no', 'name_en', 'name_am']),
            // City-wide categories and those of the user's own institutions — never another institution's.
            'categories' => PublicFeedbackCategory::query()
                ->when(
                    ! $scope->isUnrestricted($user),
                    fn ($query) => $query->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $scope->accessibleOrganizationIds($user)->all())),
                )
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am']),
        ];
    }

    /** @return array<string, bool> */
    private function abilities(?EmployeeServiceFeedback $feedback = null): array
    {
        $user = Auth::user();

        if ($feedback === null) {
            return [
                'review' => $user->hasAnyPermission(['service_feedback.review']),
                'hide' => $user->hasAnyPermission(['service_feedback.hide']),
                'delete' => $user->hasAnyPermission(['service_feedback.delete']),
                'export' => $user->can('export', EmployeeServiceFeedback::class),
            ];
        }

        return [
            'review' => $user->can('review', $feedback),
            'hide' => $user->can('hide', $feedback),
            'delete' => $user->can('delete', $feedback),
            'export' => $user->can('export', EmployeeServiceFeedback::class),
        ];
    }
}
