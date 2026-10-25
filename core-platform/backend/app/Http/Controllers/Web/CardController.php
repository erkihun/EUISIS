<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CardRequests;
use App\Actions\ChangeCardStatus;
use App\Http\Controllers\Controller;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CardEligibility;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS IdCards/* and CardRequests/* over the Core tables. Every change goes
 * through ChangeCardStatus and CardRequests (two people, one live card,
 * replacement linking). Printing is one step on the card (ADR 0006, decision 4).
 */
class CardController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'organization_id' => ['nullable', 'uuid'],
            'issued_from' => ['nullable', 'date_format:Y-m-d'],
            'expires_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $base = fn (): Builder => $this->inScope(IdCard::query(), $user);
        $page = $base()->with(['employee.currentAssignment.organization', 'employee.currentAssignment.unit', 'employee.currentAssignment.position'])
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where(fn ($inner) => $inner->where('card_number', 'like', "%{$search}%")
                ->orWhereHas('employee', fn ($e) => $e->where('employee_number', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['organization_id'] ?? null, fn ($q, string $id) => $q->whereHas('employee.currentAssignment', fn ($a) => $a->where('organization_id', $id)))
            ->when($filters['issued_from'] ?? null, fn ($q, string $date) => $q->whereDate('issued_at', '>=', $date))
            ->when($filters['expires_to'] ?? null, fn ($q, string $date) => $q->whereDate('expires_at', '<=', $date))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $ids = $this->scope->organizationIds($user);

        return Inertia::render('IdCards/Index', [
            'cards' => $this->collection($page, fn (IdCard $card): array => $this->card($card, $user)),
            'summary' => collect(['total' => null, 'active' => IdCard::ACTIVE, 'pending' => IdCard::PENDING_PRINT, 'expired' => IdCard::EXPIRED,
                'revoked' => IdCard::REVOKED, 'lost' => IdCard::LOST, 'suspended' => IdCard::SUSPENDED])
                ->map(fn (?string $status): int => $base()->when($status, fn ($q) => $q->where('status', $status))->count())->all(),
            'filters' => $filters,
            'organizations' => Organization::query()->select(['id', 'name_en', 'name_am'])->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name_en')->get(),
            'can' => ['create' => false, 'submitRequest' => $user->can('card-requests.create'), 'createPrintBatch' => false],
        ]);
    }

    public function show(Request $request, IdCard $card): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCard($user, $card);
        $card->load(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'employee.currentAssignment.unit',
            'request.requester', 'request.reviewer', 'request.approver', 'previousCard', 'replacementCard']);
        $manage = $user->can('cards.manage');
        $status = $card->status;

        return Inertia::render('IdCards/Show', [
            'portal_labels' => [
                'print_instructions' => __('Mark the card printed once the physical card has printed. Issue and activation follow on this page.'),
                'prepare_print' => __('employee-portal.print'),
            ],
            'nfc' => ['can' => ['view' => false], 'credentials' => []],
            'card' => $this->card($card, $user) + [
                'card_request' => $card->request === null ? null : [
                    'requester' => $card->request->requester?->only(['name']),
                    'reviewer' => $card->request->reviewer?->only(['name']),
                    'approver' => $card->request->approver?->only(['name']),
                ],
                'issuance' => null,
                'previous_card' => $card->previousCard?->only(['id', 'card_number']),
                'replacement_card' => $card->replacementCard?->only(['id', 'card_number']),
                'qr_verification_url' => $card->public_card_uuid ? route('id-checker.show', $card->public_card_uuid) : null,
                'feedback_qr_url' => null,
            ],
            'can' => [
                'view' => true,
                'update' => false,
                'print' => $manage && $status === IdCard::PENDING_PRINT,
                'reprint' => false,
                'issue' => $manage && $status === IdCard::PRINTED,
                'activate' => $manage && $status === IdCard::ISSUED,
                'reportLost' => $manage && in_array($status, [IdCard::ACTIVE, IdCard::ISSUED], true),
                'reportDamaged' => $manage && in_array($status, [IdCard::ACTIVE, IdCard::ISSUED], true),
                'replace' => $manage && in_array($status, IdCard::REPLACEABLE, true),
                'revoke' => $manage && ! in_array($status, [IdCard::REVOKED, IdCard::REPLACED, IdCard::EXPIRED], true),
                'suspend' => $manage && in_array($status, [IdCard::ACTIVE, IdCard::ISSUED], true),
                'reinstate' => $manage && $status === IdCard::SUSPENDED,
                'exportPng' => false,
                'previewSvg' => false,
            ],
        ]);
    }

    /** A card transition from the detail page; back with the EUISIS message. */
    public function transition(Request $request, IdCard $card, ChangeCardStatus $change, string $transition): RedirectResponse
    {
        $reason = $request->input('reason') ?? $request->input('notes');
        if (in_array($transition, ChangeCardStatus::NEEDS_REASON, true)) {
            $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        }
        $change->execute($card, $transition, is_string($reason) && $reason !== '' ? $reason : null, $request->user());
        $message = [
            'print' => 'id-cards.printed_successfully', 'issue' => 'id-cards.issued_successfully', 'activate' => 'id-cards.activated_successfully',
            'report_lost' => 'id-cards.reported_lost', 'report_damaged' => 'id-cards.reported_damaged', 'revoke' => 'id-cards.revoked_successfully',
            'suspend' => 'id-cards.suspended_successfully', 'reinstate' => 'id-cards.reinstated_successfully',
        ][$transition];

        return back()->with('success', __($message));
    }

    public function replace(Request $request, IdCard $card, CardRequests $requests): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $requests->replace($card, $request->string('reason')->toString(), $request->user());

        return redirect()->route('card-requests.index')->with('success', __('id-cards.replacement_submitted'));
    }

    public function requests(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = ['search' => $request->string('search')->toString(), 'status' => $request->string('status')->toString(), 'request_type' => $request->string('request_type')->toString()];
        $page = $this->inScope(CardRequest::query(), $user)
            ->with(['employee.currentAssignment.organization', 'requester', 'reviewer', 'approver', 'rejecter'])
            ->when($filters['search'] !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employee_number', 'like', "%{$filters['search']}%")->orWhere('full_name', 'like', "%{$filters['search']}%")))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['request_type'] !== '', fn ($q) => $q->where('request_type', $filters['request_type']))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (CardRequest $cardRequest): array => $this->requestPayload($cardRequest) + ['can' => $this->requestCan($user, $cardRequest)]));

        return Inertia::render('CardRequests/Index', [
            'cardRequests' => $page,
            'can' => ['create' => $user->can('card-requests.create')],
            'filters' => $filters,
        ]);
    }

    /** The picker lists only employees CardEligibility allows, in one bounded query (ADR 0009). */
    public function createRequest(Request $request, CardEligibility $eligibility): Response
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $limit = 1000;
        $employees = $eligibility->eligibleEmployees($request->user(), $term, $limit + 1);

        return Inertia::render('CardRequests/Create', [
            'employees' => $employees->take($limit)->map(fn (array $employee): array => array_diff_key($employee, ['organization' => true])
                + ['current_assignment' => $employee['organization'] === null ? null : ['organization' => $employee['organization']]])->values(),
            'employeesTruncated' => $employees->count() > $limit,
            'filters' => ['q' => $term],
            'requestTypes' => CardRequest::TYPES,
        ]);
    }

    public function storeRequest(Request $request, CardRequests $requests): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['nullable', Rule::in(CardRequest::TYPES)],
            'employee_ids' => ['required_without:employee_id', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['uuid'],
            'employee_id' => ['required_without:employee_ids', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $type = $data['request_type'] ?? 'new';
        $field = isset($data['employee_ids']) ? 'employee_ids' : 'employee_id';
        $employees = Employee::query()->whereIn('id', $data['employee_ids'] ?? [$data['employee_id']])->orderBy('id')->get();

        DB::transaction(function () use ($employees, $type, $data, $field, $requests, $request): void {
            foreach ($employees as $employee) {
                try {
                    if (in_array($type, ['lost', 'damaged', 'replacement'], true)) {
                        $card = IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->latest('created_at')->first();
                        if ($card === null || ! in_array($type, CardEligibility::typesFor($card->status), true)) {
                            throw ValidationException::withMessages([$field => __('id-cards.request_type_ineligible')]);
                        }
                        $requests->replace($card, $data['reason'] ?? null, $request->user());
                    } else {
                        $requests->submit($employee, $type, $data['reason'] ?? null, $request->user());
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$field => $employee->employee_number.': '.collect($exception->errors())->flatten()->first()]);
                }
            }
        });

        return redirect()->route('card-requests.index')->with('success', __('id-cards.request_submitted'));
    }

    public function showRequest(Request $request, CardRequest $cardRequest): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->inScope(CardRequest::query()->whereKey($cardRequest->id), $user)->exists(), 403);
        $cardRequest->load(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'requester', 'reviewer', 'approver', 'rejecter', 'canceller', 'previousCard', 'cards']);

        return Inertia::render('CardRequests/Show', [
            'cardRequest' => $this->requestPayload($cardRequest) + [
                'cards' => $cardRequest->cards->map(fn (IdCard $card): array => $card->only(['id', 'card_number', 'status']))->values(),
                'previous_card' => $cardRequest->previousCard?->only(['id', 'card_number', 'status']),
            ],
            'can' => $this->requestCan($user, $cardRequest),
        ]);
    }

    public function verifyRequest(Request $request, CardRequest $cardRequest, AuditWriter $audit): RedirectResponse
    {
        abort_unless($this->requestCan($request->user(), $cardRequest)['verify'], 403);
        $cardRequest->update(['status' => 'verified', 'reviewed_by' => $request->user()->id, 'verified_at' => now(), 'verification_notes' => $request->input('notes')]);
        $audit->write('card_request.verified', $request->user(), $cardRequest, $cardRequest->employee?->currentAssignment?->organization_id, null, ['status' => 'verified'], $request->input('notes'));

        return back()->with('success', __('id-cards.request_verified'));
    }

    public function approveRequest(Request $request, CardRequest $cardRequest, CardRequests $requests): RedirectResponse
    {
        $requests->approve($cardRequest, $request->user(), $request->input('notes'));

        return back()->with('success', __('id-cards.request_approved'));
    }

    public function rejectRequest(Request $request, CardRequest $cardRequest, CardRequests $requests): RedirectResponse
    {
        $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $requests->close($cardRequest, 'reject', $request->string('rejection_reason')->toString(), $request->user());

        return back()->with('success', __('id-cards.request_rejected'));
    }

    public function cancelRequest(Request $request, CardRequest $cardRequest, CardRequests $requests): RedirectResponse
    {
        $request->validate(['cancellation_reason' => ['required', 'string', 'max:1000']]);
        $requests->close($cardRequest, 'cancel', $request->string('cancellation_reason')->toString(), $request->user());

        return back()->with('success', __('id-cards.request_cancelled'));
    }

    /** Rows whose employee is placed inside the reader's organizations (all rows for a citywide reader). */
    private function inScope(Builder $query, User $user, string $employeeColumn = 'employee_id'): Builder
    {
        $ids = $this->scope->organizationIds($user);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($employeeColumn, fn ($q) => $q->select('employee_id')->from('employee_assignments')->where('is_current', true)->whereIn('organization_id', $ids));
    }

    private function authorizeCard(User $user, IdCard $card): void
    {
        abort_unless($this->inScope(IdCard::query()->whereKey($card->id), $user)->exists(), 403);
    }

    /** @return array<string, mixed> */
    private function card(IdCard $card, User $user): array
    {
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;
        $manage = $user->can('cards.manage');

        return [
            'id' => $card->id,
            'card_number' => $card->card_number,
            'status' => $card->status,
            'token_version' => $card->token_version,
            'printed_at' => $card->printed_at?->toIso8601String(),
            'issued_at' => $card->issued_at?->toIso8601String(),
            'activated_at' => $card->activated_at?->toIso8601String(),
            'expires_at' => $card->expires_at?->toIso8601String(),
            'revoked_at' => $card->revoked_at?->toIso8601String(),
            'revoke_reason' => $card->revoke_reason,
            'notes' => $card->notes,
            'is_current' => $card->is_current,
            'previous_card_id' => $card->previous_card_id,
            'card_request_id' => $card->card_request_id,
            'created_at' => $card->created_at?->toIso8601String(),
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'name_en' => $employee->name_en,
                'full_name_am' => $employee->full_name ?: $employee->name_en,
                'metadata' => null,
                'gender' => $employee->gender,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'nationality' => $employee->nationality,
                'employment_type' => $employee->employment_type,
                'employment_type_label' => $employee->employment_type === null ? null : ucfirst((string) $employee->employment_type),
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'status' => $employee->status,
                'photo_path' => null,
                'photo_url' => null,
                'current_assignment' => $assignment === null ? null : [
                    'assignment_status' => $assignment->assignment_status,
                    'organization' => $assignment->organization === null ? null : $assignment->organization->only(['id', 'name_en', 'name_am', 'code']) + ['logo_url' => null],
                    'organization_unit' => $assignment->unit?->only(['id', 'name_en', 'name_am']),
                    'position' => $assignment->position?->only(['title_en', 'title_am', 'job_position_code', 'grade_level']),
                ],
            ],
            'qr_payload' => null,
            'public_card_uuid' => $card->public_card_uuid,
            'qr_status' => $card->qr_status,
            'qr_issued_at' => null,
            'qr_rotated_at' => null,
            'can' => [
                'view' => true, 'update' => false, 'print' => $manage, 'issue' => $manage, 'activate' => $manage, 'reportLost' => $manage,
                'reportDamaged' => $manage, 'replace' => $manage, 'revoke' => $manage, 'exportPng' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function requestPayload(CardRequest $cardRequest): array
    {
        $employee = $cardRequest->employee;
        $assignment = $employee?->currentAssignment;

        return [
            'id' => $cardRequest->id,
            'request_type' => $cardRequest->request_type,
            'status' => $cardRequest->status,
            'request_reason' => $cardRequest->request_reason,
            'verification_notes' => $cardRequest->verification_notes,
            'rejection_reason' => $cardRequest->rejection_reason,
            'cancellation_reason' => $cardRequest->cancellation_reason,
            'submitted_at' => $cardRequest->submitted_at?->toIso8601String(),
            'verified_at' => $cardRequest->verified_at?->toIso8601String(),
            'approved_at' => $cardRequest->approved_at?->toIso8601String(),
            'rejected_at' => $cardRequest->rejected_at?->toIso8601String(),
            'cancelled_at' => $cardRequest->cancelled_at?->toIso8601String(),
            'created_at' => $cardRequest->created_at?->toIso8601String(),
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'status' => $employee->status,
                'photo_url' => null,
                'photo_path' => null,
                'current_assignment' => $assignment === null ? null : [
                    'organization' => $assignment->organization?->only(['id', 'name_en', 'name_am']),
                    'position' => $assignment->relationLoaded('position') ? $assignment->position?->only(['id', 'title_en', 'title_am']) : null,
                ],
            ],
            'requested_by' => $cardRequest->requester?->only(['id', 'name']),
            'reviewed_by' => $cardRequest->reviewer?->only(['id', 'name']),
            'approved_by' => $cardRequest->approver?->only(['id', 'name']),
            'rejected_by' => $cardRequest->rejecter?->only(['id', 'name']),
        ];
    }

    /** @return array{view: bool, verify: bool, approve: bool, reject: bool, cancel: bool} */
    private function requestCan(User $user, CardRequest $cardRequest): array
    {
        $pending = in_array($cardRequest->status, CardRequest::PENDING, true);
        $other = (int) $cardRequest->requested_by !== (int) $user->id;

        return [
            'view' => true,
            'verify' => $pending && $cardRequest->status !== 'verified' && $user->can('card-requests.approve'),
            'approve' => $pending && $other && $user->can('card-requests.approve'),
            'reject' => $pending && $user->can('card-requests.approve'),
            'cancel' => $pending && $user->can('card-requests.create'),
        ];
    }

    /**
     * A JSON-resource-collection shape (data, links, meta) for a paginator.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, mixed>
     */
    private function collection($page, callable $map): array
    {
        return [
            'data' => $page->getCollection()->map($map)->values(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(), 'from' => $page->firstItem(), 'last_page' => $page->lastPage(),
                'links' => $page->linkCollection()->toArray(), 'path' => $page->path(), 'per_page' => $page->perPage(),
                'to' => $page->lastItem(), 'total' => $page->total(),
            ],
        ];
    }
}
