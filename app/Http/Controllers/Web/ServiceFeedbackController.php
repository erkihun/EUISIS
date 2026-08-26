<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\PositionService;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\ServiceFeedbackQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        'position_service_id',
        'service_no',
        'rating',
        'status',
        'date_from',
        'date_to',
    ];

    public function __construct(
        private readonly ServiceFeedbackQueryService $feedbackQuery,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeServiceFeedback::class);

        $user = Auth::user();
        $filters = $request->only(self::FILTER_KEYS);

        $base = $this->feedbackQuery->applyFilters(
            $this->feedbackQuery->scopedQuery($user),
            $filters,
        );

        return Inertia::render('ServiceFeedback/Dashboard', [
            'summary' => $this->feedbackQuery->summary($base),
            'ratingDistribution' => $this->feedbackQuery->ratingDistribution($base),
            'byOrganization' => $this->feedbackQuery->byOrganization($base),
            'byEmployee' => $this->feedbackQuery->byEmployee($base),
            'byServiceType' => $this->feedbackQuery->byServiceType($base),
            'recentComments' => $this->feedbackQuery->recentComments($base)
                ->map(fn (EmployeeServiceFeedback $item): array => $this->summarise($item)),
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
            ->applyFilters($this->feedbackQuery->scopedQuery($user), $filters)
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
                'position:id,title_en,title_am',
                'positionService:id,service_no,name_en,name_am',
            ])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EmployeeServiceFeedback $item): array => $this->summarise($item));

        return Inertia::render('ServiceFeedback/Index', [
            'feedback' => $feedback,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($user),
            'statuses' => array_column(ServiceFeedbackStatus::cases(), 'value'),
            'can' => $this->abilities(),
        ]);
    }

    public function show(EmployeeServiceFeedback $serviceFeedback): Response
    {
        $this->authorize('view', $serviceFeedback);

        $serviceFeedback->load([
            'employee:id,full_name,employee_number',
            'organization:id,name_en,name_am',
            'organizationUnit:id,name_en,name_am',
            'position:id,title_en,title_am',
            'positionService:id,service_no,name_en,name_am',
            'reviewer:id,name',
        ]);

        return Inertia::render('ServiceFeedback/Show', [
            'feedback' => array_merge($this->summarise($serviceFeedback), [
                'review_note' => $serviceFeedback->review_note,
                'reviewed_at' => $serviceFeedback->reviewed_at?->toIso8601String(),
                'reviewed_by' => $serviceFeedback->reviewer?->name,
            ]),
            'can' => $this->abilities($serviceFeedback),
        ]);
    }

    /** Mark an entry reviewed or resolved. */
    public function review(Request $request, EmployeeServiceFeedback $serviceFeedback): RedirectResponse
    {
        $this->authorize('review', $serviceFeedback);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:reviewed,resolved'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = ServiceFeedbackStatus::from($data['status']);

        $serviceFeedback->forceFill([
            'status' => $status,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();

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
        $this->authorize('hide', $serviceFeedback);

        $hiding = $serviceFeedback->status !== ServiceFeedbackStatus::Hidden;

        $serviceFeedback->forceFill([
            'status' => $hiding ? ServiceFeedbackStatus::Hidden : ServiceFeedbackStatus::Pending,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();

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

    public function destroy(Request $request, EmployeeServiceFeedback $serviceFeedback): RedirectResponse
    {
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
            ->applyFilters($this->feedbackQuery->scopedQuery($user), $filters)
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
                'positionService:id,service_no,name_en,name_am',
            ])
            ->latest('created_at');

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackExported,
            actor: $user,
            reason: 'Client feedback exported to CSV',
            request: $request,
        );

        $filename = 'service-feedback-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens the Amharic columns as UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Submitted At', 'Employee', 'Employee No.', 'Organization',
                'Unit', 'Service Type', 'Rating', 'Status', 'Comment',
                'Client Name', 'Client Contact',
            ]);

            $query->chunk(500, function ($rows) use ($handle): void {
                foreach ($rows as $item) {
                    fputcsv($handle, [
                        $item->created_at?->toDateTimeString(),
                        $item->employee?->full_name,
                        $item->employee?->employee_number,
                        $item->organization?->name_en,
                        $item->organizationUnit?->name_en,
                        $item->service_name_snapshot ?? $item->positionService?->name_en,
                        $item->rating,
                        $item->status->value,
                        $item->comment,
                        $item->client_name,
                        $item->client_contact,
                    ]);
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
            'client_name' => $item->client_name,
            'client_contact' => $item->client_contact,
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
