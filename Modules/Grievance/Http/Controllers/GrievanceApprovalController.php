<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Http\Controllers\Controller;
use App\Models\GrievanceDecision;
use App\Models\User;
use App\Services\Grievances\GrievanceApproverResolver;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievancePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive approvals: only decisions routed to a position the user holds
 * (or holds by delegation). Never every confidential case.
 */
class GrievanceApprovalController extends Controller
{
    public function __construct(
        private readonly GrievanceApproverResolver $approvers,
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievancePresenter $presenter,
    ) {}

    /** @return Builder<GrievanceDecision> */
    public function pendingQuery(User $user): Builder
    {
        $positions = $this->approvers->positionsOf($user);

        return GrievanceDecision::query()
            ->whereIn('status', [GrievanceDecisionStatus::PendingExecutiveApproval->value, GrievanceDecisionStatus::Resubmitted->value])
            ->whereIn('approver_position_id', $positions)
            ->whereHas('grievance', fn ($g) => $g->where(fn ($q) => $q->whereNull('submitted_by_user_id')->orWhere('submitted_by_user_id', '!=', $user->getKey())));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_decisions.approve'), 403);
        $tab = in_array($request->string('tab')->value(), ['pending', 'history'], true) ? $request->string('tab')->value() : 'pending';

        $query = $tab === 'pending'
            ? $this->pendingQuery($user)
            : GrievanceDecision::query()->whereHas('approvals', fn ($a) => $a->where('actor_user_id', $user->getKey())->whereIn('action', ['approve', 'return_for_correction', 'reject']));

        $page = $query->with(['grievance.category', 'grievance.organization', 'stage', 'approverPosition'])
            ->orderBy('approval_due_at')->orderByDesc('submitted_for_approval_at')
            ->paginate(20)->withQueryString();

        $page->through(function (GrievanceDecision $d) use ($user): array {
            $g = $d->grievance;

            return [
                'id' => $d->getKey(),
                'grievance_id' => $g->getKey(),
                'reference_number' => $g->reference_number,
                'category' => $g->category?->only(['id', 'name_en', 'name_am']),
                'organization' => $g->organization?->only(['id', 'name_en', 'name_am']),
                'confidentiality_level' => $g->confidentiality_level?->value,
                'handler' => $d->stage ? $this->presenter->handler($d->stage->handler_type->value, $d->stage->handler_id) : null,
                'version_no' => $d->version_no,
                'decision_type' => $d->decision_type?->value,
                'status' => $d->status?->value,
                'submitted_for_approval_at' => $d->submitted_for_approval_at?->toIso8601String(),
                'approval_due_at' => $d->approval_due_at?->toIso8601String(),
                'overdue' => $d->approval_due_at !== null && $d->approval_due_at->isPast() && $this->access->isPendingApproval($d),
                'can_act' => $this->access->canApprove($user, $d),
            ];
        });

        return Inertia::render('Grievances/Approvals/Index', [
            'decisions' => $page,
            'tab' => $tab,
            'counts' => ['pending' => $this->pendingQuery($user)->count()],
        ]);
    }
}
