<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;

/**
 * Hands a public submission to a handler and keeps every hand-over (brief
 * §53). A handler must belong to the submission's institution — a unit of
 * that organization, a user who may access it, or a role — and is never the
 * employee the submission is about (§54).
 */
final class PublicFeedbackAssignmentService
{
    public function __construct(
        private readonly PublicFeedbackWorkflow $workflow,
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
        private readonly PublicFeedbackNotifier $notifier,
    ) {}

    /**
     * @throws ValidationException when the handler does not exist, sits outside
     *                             the submission's organization, is its target
     *                             employee, or the submission is past assignment
     */
    public function assign(
        EmployeeServiceFeedback $submission,
        PublicFeedbackHandlerType $type,
        string $handlerId,
        ?User $actor,
        ?string $reason = null,
        ?PublicFeedbackRoutingRule $rule = null,
        ?Request $request = null,
    ): PublicFeedbackAssignment {
        $names = $type->names($handlerId);

        if ($names === null) {
            throw ValidationException::withMessages(['handler_id' => __('service-feedback.handler_invalid')]);
        }

        $this->assertHandlerWithinOrganization($type, $handlerId, $submission->organization_id, $submission->employee_id);

        if (! in_array($submission->status, [
            ServiceFeedbackStatus::Pending, ServiceFeedbackStatus::Unrouted, ServiceFeedbackStatus::Assigned, ServiceFeedbackStatus::UnderReview,
        ], true)) {
            throw ValidationException::withMessages(['handler_id' => __('service-feedback.not_assignable')]);
        }

        return DB::transaction(function () use ($submission, $type, $handlerId, $names, $actor, $reason, $rule, $request): PublicFeedbackAssignment {
            $now = now();
            $previous = $submission->currentAssignment()->first();

            if ($previous !== null) {
                $previous->forceFill(['ended_at' => $now, 'ended_by' => $actor?->getKey()])->save();
            }

            $assignment = PublicFeedbackAssignment::query()->create([
                'submission_id' => $submission->getKey(),
                'handler_type' => $type,
                'handler_id' => $handlerId,
                'handler_name_snapshot' => $names['en'],
                'handler_name_am_snapshot' => $names['am'],
                'routing_rule_id' => $rule?->getKey(),
                'assigned_by' => $actor?->getKey(),
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'assigned_at' => $now,
            ]);

            if (in_array($submission->status, [ServiceFeedbackStatus::Pending, ServiceFeedbackStatus::Unrouted], true)) {
                $this->workflow->transition(
                    $submission,
                    ServiceFeedbackStatus::Assigned,
                    $actor,
                    $rule !== null ? null : $reason,
                    $request,
                );
            }

            $this->audit->execute(
                eventType: $previous !== null ? AuditEventType::ServiceFeedbackReassigned : AuditEventType::ServiceFeedbackAssigned,
                actor: $actor,
                auditable: $submission,
                organizationId: $submission->organization_id,
                oldValues: $previous !== null ? ['handler' => $previous->handler_type->value.':'.$previous->handler_id] : null,
                newValues: ['handler' => $type->value.':'.$handlerId, 'routing_rule_id' => $rule?->getKey()],
                reason: $reason,
                request: $request,
            );

            $submission->setRelation('currentAssignment', $assignment);
            // Brief §88: the people behind the handler hear of it after commit.
            $this->notifier->assigned($submission, $assignment, $actor);

            return $assignment;
        });
    }

    /**
     * A unit must be the organization's own; a user must be allowed to work in
     * it (or be unrestricted); a role is city-wide by nature. With no
     * organization on file only unrestricted users and roles qualify.
     *
     * @throws ValidationException
     */
    public function assertHandlerWithinOrganization(
        PublicFeedbackHandlerType $type,
        string $handlerId,
        ?string $organizationId,
        ?string $targetEmployeeId = null,
    ): void {
        $ok = match ($type) {
            PublicFeedbackHandlerType::OrganizationUnit => $organizationId !== null
                && OrganizationUnit::query()->whereKey($handlerId)->where('organization_id', $organizationId)->exists(),
            PublicFeedbackHandlerType::User => (function () use ($handlerId, $organizationId, $targetEmployeeId): bool {
                $user = User::query()->find($handlerId);
                if ($user === null) {
                    return false;
                }
                if ($targetEmployeeId !== null && $user->employee_id !== null && (string) $user->employee_id === (string) $targetEmployeeId) {
                    throw ValidationException::withMessages(['handler_id' => __('service-feedback.handler_is_target')]);
                }

                return $this->scope->isUnrestricted($user)
                    || ($organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId));
            })(),
            PublicFeedbackHandlerType::Role => Role::query()->where('guard_name', 'web')->whereKey($handlerId)->exists(),
        };

        if (! $ok) {
            throw ValidationException::withMessages(['handler_id' => __('service-feedback.handler_out_of_scope')]);
        }
    }

    /**
     * Handlers an actor may pick for an organization: reviewers who can work
     * there, its units, and every role.
     *
     * @return array{users: list<array{id: string, name: string}>, units: list<array{id: string, name: string, name_am: ?string}>, roles: list<array{id: string, name: string}>}
     */
    public function options(?string $organizationId): array
    {
        $users = User::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->permission(['service_feedback.review', 'service_feedback.complaints.review']);
            })
            ->orderBy('name')
            ->limit(300)
            ->get()
            ->filter(fn (User $user): bool => $this->scope->isUnrestricted($user)
                || ($organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId)))
            ->map(fn (User $user): array => ['id' => (string) $user->getKey(), 'name' => (string) $user->name])
            ->values()
            ->all();

        $units = $organizationId === null ? [] : OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am'])
            ->map(fn (OrganizationUnit $unit): array => ['id' => (string) $unit->id, 'name' => (string) $unit->name_en, 'name_am' => $unit->name_am])
            ->all();

        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Role $role): array => ['id' => (string) $role->getKey(), 'name' => (string) $role->name])
            ->all();

        return ['users' => $users, 'units' => $units, 'roles' => $roles];
    }
}
