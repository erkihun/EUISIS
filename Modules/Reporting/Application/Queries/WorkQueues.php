<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/** The lists people work from: what I owe, what awaits my review, follow-up actions, the calendar. */
final readonly class WorkQueues
{
    /**
     * Obligations on the actor's reporting side.
     *
     * @return Builder<Obligation>
     */
    public function assignments(ActorContext $actor, string $view = 'open'): Builder
    {
        $query = Obligation::query()->with('cycle.reportType')->where('applicability', 'required');
        if (! $actor->can(ReportingRoles::PREPARE) && ! $actor->can(ReportingRoles::SUBMIT)) {
            return $query->whereRaw('1 = 0');
        }
        $query->where(function (Builder $q) use ($actor): void {
            $q->where('person_user_id', $actor->userId)->orWhere('preparer_user_id', $actor->userId)->orWhere('accountable_user_id', $actor->userId);
            // A city-wide account is not "assigned" every institution's report; it sees them in the register.
            if (! $actor->unrestricted && $actor->organizationIds !== []) {
                $q->orWhere(fn (Builder $own) => $own->whereNull('person_user_id')->whereIn('organization_id', $actor->organizationIds));
            }
        });

        return match ($view) {
            'done' => $query->whereIn('workflow_status', [WorkflowStatus::Approved->value, WorkflowStatus::Rejected->value])->orderByDesc('decided_at'),
            'waiting' => $query->whereIn('workflow_status', [WorkflowStatus::Submitted->value, WorkflowStatus::UnderReview->value])->orderBy('review_due_at'),
            default => $query->whereIn('workflow_status', [WorkflowStatus::NotStarted->value, WorkflowStatus::Draft->value, WorkflowStatus::Returned->value])
                ->where('opens_at', '<=', CarbonImmutable::now('UTC'))->orderBy('effective_due_at'),
        };
    }

    /**
     * Submissions awaiting a decision the actor could take: the receiving side, with a review permission.
     *
     * @return Builder<Obligation>
     */
    public function reviewInbox(ActorContext $actor, bool $mineOnly = false): Builder
    {
        $query = Obligation::query()->with('cycle.reportType')->whereIn('workflow_status', [WorkflowStatus::Submitted->value, WorkflowStatus::UnderReview->value]);
        if (! $actor->can(ReportingRoles::REVIEW) && ! $actor->can(ReportingRoles::APPROVE)) {
            return $query->whereRaw('1 = 0');
        }
        if ($mineOnly) {
            $query->where('current_reviewer_user_id', $actor->userId);
        } elseif (! $actor->unrestricted) {
            $query->where(fn (Builder $q) => $q->whereIn('receiving_organization_id', $actor->organizationIds)->orWhere('current_reviewer_user_id', $actor->userId));
        }

        return $query->orderByRaw('review_due_at IS NULL')->orderBy('review_due_at')->orderBy('latest_submitted_at');
    }

    /**
     * Extension requests awaiting a decision on the actor's receiving side.
     *
     * @return Builder<Extension>
     */
    public function pendingExtensions(ActorContext $actor): Builder
    {
        $query = Extension::query()->where('status', 'pending')->orderBy('requested_at');
        if (! $actor->can(ReportingRoles::GRANT_EXTENSIONS)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->unrestricted ? $query : $query->whereIn('obligation_id', Obligation::query()->whereIn('receiving_organization_id', $actor->organizationIds)->select('id'));
    }

    /**
     * @param  array{status?: ?string, mine?: bool}  $filters
     * @return LengthAwarePaginator<int, FollowUp>
     */
    public function followUps(ActorContext $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $visible = ReportingGuard::visible(Obligation::query(), $actor)->select('id');

        return FollowUp::query()
            ->where(fn (Builder $q) => $q->whereIn('obligation_id', $visible)->orWhere('owner_user_id', $actor->userId))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active' ? $q->whereIn('status', ['open', 'in_progress', 'done']) : $q->where('status', $status))
            ->when($filters['mine'] ?? false, fn (Builder $q) => $q->where('owner_user_id', $actor->userId))
            ->orderByRaw("CASE WHEN status IN ('closed', 'cancelled') THEN 1 ELSE 0 END")->orderBy('due_at')
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Deadlines in a date range, one entry per cycle with the counts the actor may see.
     *
     * @return list<array<string, mixed>>
     */
    public function calendar(ActorContext $actor, string $fromIso, string $toIso): array
    {
        $rows = ReportingGuard::visible(Obligation::query(), $actor)->where('applicability', 'required')
            ->whereBetween('effective_due_at', [ComplianceQuery::localDay($fromIso, false), ComplianceQuery::localDay($toIso, true)])
            ->selectRaw('cycle_id, effective_due_at, COUNT(*) AS expected, COUNT(first_submitted_at) AS submitted')
            ->groupBy('cycle_id', 'effective_due_at')->orderBy('effective_due_at')->limit(500)->get();
        $cycles = Cycle::query()->with('reportType')->whereKey($rows->pluck('cycle_id')->unique())->get()->keyBy('id');

        return $rows->map(fn (Obligation $row): array => [
            'cycle_id' => $row->cycle_id,
            'due_at' => $row->effective_due_at->toIso8601String(),
            'opens_at' => $cycles[$row->cycle_id]->opens_at->toIso8601String(),
            'report_type' => ['name_en' => $cycles[$row->cycle_id]->reportType->name_en, 'name_am' => $cycles[$row->cycle_id]->reportType->name_am],
            'period' => ['label_en' => $cycles[$row->cycle_id]->period_label_en, 'label_am' => $cycles[$row->cycle_id]->period_label_am],
            'calendar_basis' => $cycles[$row->cycle_id]->calendar_basis,
            'expected' => (int) $row->expected,
            'submitted' => (int) $row->submitted,
        ])->all();
    }
}
