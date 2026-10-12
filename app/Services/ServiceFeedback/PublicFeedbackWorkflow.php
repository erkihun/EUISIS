<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Type-specific workflows over one status vocabulary (brief §48–52).
 *
 *   feedback    pending → reviewed | resolved → closed
 *   suggestion  pending → assigned | unrouted → under_review → accepted | not_adopted → closed
 *   complaint   pending → assigned | unrouted → under_review → resolved → closed
 *
 * A suggestion may be taken under review without an assignment (it is the
 * lighter of the two routed types); a complaint is reviewed only once it has
 * a handler. `reviewed`/`resolved` rows of the old single review step close
 * normally. Every transition appends an immutable history row.
 */
final class PublicFeedbackWorkflow
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @return array<string, list<ServiceFeedbackStatus>> from-status value → reachable statuses */
    public function transitions(ServiceFeedbackType $type): array
    {
        $s = ServiceFeedbackStatus::class;

        return match ($type) {
            ServiceFeedbackType::Feedback => [
                'pending' => [$s::Reviewed, $s::Resolved],
                'reviewed' => [$s::Resolved, $s::Closed],
                'resolved' => [$s::Closed],
            ],
            ServiceFeedbackType::Suggestion => [
                'pending' => [$s::Assigned, $s::Unrouted, $s::UnderReview],
                'unrouted' => [$s::Assigned, $s::UnderReview],
                'assigned' => [$s::UnderReview],
                'under_review' => [$s::Accepted, $s::NotAdopted],
                'accepted' => [$s::Closed],
                'not_adopted' => [$s::Closed],
                'reviewed' => [$s::Closed],
                'resolved' => [$s::Closed],
            ],
            ServiceFeedbackType::Complaint => [
                'pending' => [$s::Assigned, $s::Unrouted],
                'unrouted' => [$s::Assigned],
                'assigned' => [$s::UnderReview],
                'under_review' => [$s::Resolved],
                'resolved' => [$s::Closed],
                'reviewed' => [$s::Closed],
            ],
        };
    }

    /** @return list<ServiceFeedbackStatus> */
    public function allowedFrom(ServiceFeedbackType $type, ServiceFeedbackStatus $from): array
    {
        return $this->transitions($type)[$from->value] ?? [];
    }

    public function canTransition(EmployeeServiceFeedback $submission, ServiceFeedbackStatus $to): bool
    {
        return in_array($to, $this->allowedFrom($submission->type, $submission->status), true);
    }

    /**
     * Move the submission, stamp the matching timestamp, append history and audit.
     *
     * @throws PublicFeedbackTransitionException
     */
    public function transition(
        EmployeeServiceFeedback $submission,
        ServiceFeedbackStatus $to,
        ?User $actor,
        ?string $reason = null,
        ?Request $request = null,
    ): EmployeeServiceFeedback {
        $from = $submission->status;

        if (! $this->canTransition($submission, $to)) {
            throw new PublicFeedbackTransitionException($submission->type, $from, $to);
        }

        return DB::transaction(function () use ($submission, $from, $to, $actor, $reason, $request): EmployeeServiceFeedback {
            $now = now();
            $attributes = ['status' => $to];

            if ($to === ServiceFeedbackStatus::Assigned && $submission->assigned_at === null) {
                $attributes['assigned_at'] = $now;
            }
            if (in_array($to, [ServiceFeedbackStatus::Reviewed, ServiceFeedbackStatus::UnderReview], true)) {
                $attributes['acknowledged_at'] = $submission->acknowledged_at ?? $now;
            }
            if ($to->isOutcome()) {
                $attributes['resolved_at'] = $now;
            }
            if ($to === ServiceFeedbackStatus::Closed) {
                $attributes['closed_at'] = $now;
            }
            if ($actor !== null && ($to->isOutcome() || in_array($to, [ServiceFeedbackStatus::Reviewed, ServiceFeedbackStatus::UnderReview], true))) {
                // The single-reviewer columns of the first version stay meaningful.
                $attributes['reviewed_by'] = $actor->getKey();
                $attributes['reviewed_at'] = $now;
            }

            $submission->forceFill($attributes)->save();
            $this->record($submission, $from, $to, $actor, $reason, $now);

            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackStatusChanged,
                actor: $actor,
                auditable: $submission,
                organizationId: $submission->organization_id,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
                reason: $reason,
                request: $request,
            );

            return $submission;
        });
    }

    /** The first history row: nothing → pending, at submission time, by nobody. */
    public function recordInitial(EmployeeServiceFeedback $submission): PublicFeedbackStatusHistory
    {
        return $this->record($submission, null, $submission->status, null, null, $submission->submitted_at ?? now());
    }

    /**
     * Append one history row without checking the workflow — for the states
     * set outside it (moderation hides, the first version's review step).
     */
    public function record(
        EmployeeServiceFeedback $submission,
        ?ServiceFeedbackStatus $from,
        ServiceFeedbackStatus $to,
        ?User $actor,
        ?string $reason = null,
        mixed $at = null,
    ): PublicFeedbackStatusHistory {
        return PublicFeedbackStatusHistory::query()->create([
            'submission_id' => $submission->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'transitioned_at' => $at ?? now(),
        ]);
    }
}
