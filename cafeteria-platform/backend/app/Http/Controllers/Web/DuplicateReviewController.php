<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ReviewEmployeeDuplicates;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDuplicateReview;
use App\Models\User;
use App\Services\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/DuplicateReviews: the duplicate review queue (ADR 0009). Only reviews
 * whose flagged record is inside the reader's scope are listed; each shows
 * the two records side by side, the other record in full only when it is
 * inside the scope too. A national ID is never shown, only whether one is on
 * record for both.
 */
class DuplicateReviewController extends Controller
{
    private const TABS = ['pending', 'needs_more_information', 'confirmed_duplicate', 'not_duplicate'];

    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        $status = $request->validate(['status' => ['sometimes', Rule::in(self::TABS)]])['status'] ?? 'pending';
        /** @var User $user */
        $user = $request->user();
        $organizations = $this->scope->organizationIds($user);
        $base = EmployeeDuplicateReview::query()
            ->when($organizations !== null, fn ($q) => $q->whereHas('employee.currentAssignment', fn ($a) => $a->whereIn('organization_id', $organizations)));
        $counts = (clone $base)->toBase()->groupBy('decision')->selectRaw('decision, count(*) as total')->pluck('total', 'decision');
        $page = (clone $base)->where('decision', $status)
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'matchedEmployee.currentAssignment.organization', 'matchedEmployee.currentAssignment.position'])
            ->orderByDesc('created_at')->paginate(20)->withQueryString();
        $names = User::query()->whereIn('id', collect($page->items())->flatMap(fn ($r) => [$r->detected_by, $r->decided_by])->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Core/DuplicateReviews', [
            'reviews' => [
                'data' => collect($page->items())->map(fn (EmployeeDuplicateReview $review): array => [
                    'id' => $review->id,
                    'match_level' => $review->match_level,
                    'reasons' => $review->reasons ?? [],
                    'decision' => $review->decision,
                    'source' => $review->source,
                    'registration_reason' => $review->registration_reason,
                    'reason' => $review->reason,
                    'notes' => $review->notes,
                    'detected_by' => $names->get($review->detected_by),
                    'detected_at' => ($review->detected_at ?? $review->created_at)?->toIso8601String(),
                    'decided_by' => $names->get($review->decided_by),
                    'decided_at' => $review->decided_at?->toIso8601String(),
                    'national_id_on_both' => $review->employee?->national_id_hash !== null && $review->matchedEmployee?->national_id_hash !== null,
                    'flagged' => $this->side($review->employee, $user),
                    'matched' => $this->side($review->matchedEmployee, $user),
                    'can_decide' => in_array($review->decision, EmployeeDuplicateReview::OPEN, true) && $user->can('employees.duplicates.review')
                        && (int) $review->detected_by !== (int) $user->getKey(),
                    'decide_url' => route('core.duplicates.decide', $review),
                ])->values(),
                'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total(),
                    'from' => $page->firstItem(), 'to' => $page->lastItem()],
            ],
            'status' => $status,
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $tab): array => [$tab => (int) ($counts[$tab] ?? 0)]),
            'url' => route('core.duplicates'),
        ]);
    }

    public function decide(Request $request, EmployeeDuplicateReview $duplicateReview, ReviewEmployeeDuplicates $reviews): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(EmployeeDuplicateReview::DECISIONS)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $reviews->decide($duplicateReview, $data['decision'], $data['reason'], $data['notes'] ?? null, $request->user());

        return back()->with('flash', ['message' => __('employees.duplicate_review_done.'.$data['decision']), 'type' => 'success']);
    }

    /** @return array<string, mixed>|null */
    private function side(?Employee $employee, User $user): ?array
    {
        if ($employee === null) {
            return null;
        }
        $assignment = $employee->currentAssignment;
        $visible = $this->scope->allows($user, $assignment?->organization_id === null ? null : (string) $assignment->organization_id);
        $base = ['employee_number' => $employee->employee_number, 'full_name' => $employee->full_name, 'in_scope' => $visible];

        return ! $visible ? $base : $base + [
            'id' => $employee->id,
            'status' => $employee->status,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'phone' => $employee->phone,
            'email' => $employee->email,
            'organization' => $assignment?->organization?->only(['id', 'name_en', 'name_am']),
            'position' => $assignment?->position?->only(['id', 'title_en', 'title_am']),
            'url' => route('employees.show', $employee),
        ];
    }
}
