<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\SituationRoomAction;
use App\Models\SituationRoomActionEvent;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records and follows up management actions. The institution must be in the
 * actor's scope, and so must the owner (a user who can see that institution,
 * or a unit of it). Every change appends a history row and an audit entry.
 *
 * Deliberately separate from the submission workflow: creating, assigning or
 * closing an action never touches a submission's handler or status.
 */
final class SituationRoomActionService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data, ?Request $request = null): SituationRoomAction
    {
        $this->assertOwner($data['organization_id'], $data['owner_user_id'] ?? null, $data['owner_organization_unit_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $request): SituationRoomAction {
            $action = SituationRoomAction::query()->create([
                'organization_id' => $data['organization_id'],
                'source_type' => $data['source_type'] ?? null,
                'source_reference' => $data['source_reference'] ?? null,
                'title' => $data['title'],
                'action_text' => $data['action_text'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'owner_organization_unit_id' => $data['owner_organization_unit_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'created_by' => $actor->getKey(),
            ]);

            $this->event($action, $actor, 'created', null, null, $data['note'] ?? null);
            if ($this->ownerLabel($action) !== null) {
                $this->event($action, $actor, 'assigned', null, $this->ownerName($action), null);
            }
            $this->audit->execute(
                eventType: AuditEventType::SituationRoomActionCreated,
                actor: $actor,
                auditable: $action,
                organizationId: (string) $action->organization_id,
                newValues: $this->auditValues($action),
                request: $request,
            );

            return $action;
        });
    }

    /** @param  array<string, mixed>  $data  validated: status, owner, due date, priority, note */
    public function update(User $actor, SituationRoomAction $action, array $data, ?Request $request = null): SituationRoomAction
    {
        $ownerChanged = array_key_exists('owner_user_id', $data) || array_key_exists('owner_organization_unit_id', $data);
        if ($ownerChanged) {
            $this->assertOwner((string) $action->organization_id, $data['owner_user_id'] ?? null, $data['owner_organization_unit_id'] ?? null);
        }

        return DB::transaction(function () use ($actor, $action, $data, $ownerChanged, $request): SituationRoomAction {
            $note = $data['note'] ?? null;

            if ($ownerChanged) {
                $from = $this->ownerLabel($action);
                $fromName = $this->ownerName($action);
                $action->owner_user_id = $data['owner_user_id'] ?? null;
                $action->owner_organization_unit_id = $data['owner_organization_unit_id'] ?? null;
                if ($from !== $this->ownerLabel($action)) {
                    $this->event($action, $actor, 'assigned', $fromName, $this->ownerName($action), $note);
                    $note = null;
                    $this->audit->execute(AuditEventType::SituationRoomActionAssigned, $actor, $action, (string) $action->organization_id, ['owner' => $from], ['owner' => $this->ownerLabel($action)], request: $request);
                }
            }

            foreach (['due_date', 'priority', 'title', 'action_text'] as $field) {
                if (array_key_exists($field, $data)) {
                    $action->{$field} = $data[$field];
                }
            }
            if ($action->isDirty('due_date')) {
                $this->event($action, $actor, 'due_date_changed', $action->getOriginal('due_date')?->toDateString(), $action->due_date?->toDateString(), null);
            }

            if (isset($data['status']) && $data['status'] !== $action->status) {
                $from = $action->status;
                $action->status = $data['status'];
                $action->closed_at = $action->isClosed() ? now() : null;
                $this->event($action, $actor, 'status_changed', $from, $action->status, $note);
                $note = null;
                $this->audit->execute(AuditEventType::SituationRoomActionStatusChanged, $actor, $action, (string) $action->organization_id, ['status' => $from], ['status' => $action->status], request: $request);
            }

            if ($note !== null && $note !== '') {
                $this->event($action, $actor, 'note', null, null, $note);
            }

            $action->save();

            return $action;
        });
    }

    /** The owner must work for that institution or a unit of it, and be reachable within its scope. */
    private function assertOwner(string $organizationId, mixed $userId, mixed $unitId): void
    {
        if ($userId !== null) {
            $owner = User::query()->find($userId);
            if ($owner === null || ! $this->scope->canAccess($owner, $organizationId)) {
                throw ValidationException::withMessages(['owner_user_id' => __('situation-room.owner_out_of_scope')]);
            }
        }

        if ($unitId !== null) {
            $unit = OrganizationUnit::query()->find($unitId);
            if ($unit === null || (string) $unit->organization_id !== $organizationId) {
                throw ValidationException::withMessages(['owner_organization_unit_id' => __('situation-room.unit_out_of_scope')]);
            }
        }
    }

    private function event(SituationRoomAction $action, User $actor, string $event, ?string $from, ?string $to, ?string $note): void
    {
        SituationRoomActionEvent::query()->create([
            'action_id' => $action->getKey(),
            'event' => $event,
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }

    /** The owner as people read it in the history. */
    private function ownerName(SituationRoomAction $action): ?string
    {
        $name = match (true) {
            $action->owner_user_id !== null => User::query()->whereKey($action->owner_user_id)->value('name'),
            $action->owner_organization_unit_id !== null => OrganizationUnit::query()->whereKey($action->owner_organization_unit_id)->value('name_en'),
            default => null,
        };

        return $name !== null ? mb_substr((string) $name, 0, 100) : null;
    }

    private function ownerLabel(SituationRoomAction $action): ?string
    {
        return match (true) {
            $action->owner_user_id !== null => 'user:'.$action->owner_user_id,
            $action->owner_organization_unit_id !== null => 'unit:'.$action->owner_organization_unit_id,
            default => null,
        };
    }

    /** Identifiers and state only; the action text stays out of the audit trail. */
    private function auditValues(SituationRoomAction $action): array
    {
        return [
            'title' => $action->title,
            'status' => $action->status,
            'priority' => $action->priority,
            'owner' => $this->ownerLabel($action),
            'due_date' => $action->due_date?->toDateString(),
            'source_type' => $action->source_type,
            'source_reference' => $action->source_reference,
        ];
    }
}
