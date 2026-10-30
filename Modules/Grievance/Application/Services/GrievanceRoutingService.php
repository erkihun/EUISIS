<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\CommitteeType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceSlaPurpose;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Enums\GrievanceStatus;
use App\Models\AdministrativeTribunalCase;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceExternalAuthority;
use App\Models\GrievanceRoute;
use App\Models\GrievanceStageMember;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The grievance routing graph (docs/grievance-management.md §5).
 *
 * Administrative hierarchy is NOT grievance hierarchy: every move follows an
 * explicitly configured, approved, effective route. Routes may cross
 * organizations and many sources may share one target. New cases use the
 * routes in force when they move; existing stages keep the route they used.
 */
final class GrievanceRoutingService
{
    /** Stage status the previous stage ends in, per movement. */
    private const CLOSING_STATUS = [
        'timeout_escalation' => GrievanceStageStatus::Escalated,
        'manual_escalation' => GrievanceStageStatus::Escalated,
        'employee_appeal' => GrievanceStageStatus::Appealed,
        'referred' => GrievanceStageStatus::Referred,
        'reassigned' => GrievanceStageStatus::Reassigned,
        'returned' => GrievanceStageStatus::Reassigned,
        'other' => GrievanceStageStatus::Closed,
    ];

    public function __construct(
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
    ) {}

    /**
     * Routes usable today from a source for a movement, best first.
     *
     * @return Collection<int, GrievanceRoute>
     */
    public function routesFrom(GrievanceHandlerType $sourceType, array $sourceIds, GrievanceMovementType $movement, ?string $categoryId): Collection
    {
        $day = now()->toDateString();

        return GrievanceRoute::query()
            ->where('source_handler_type', $sourceType->value)
            ->whereIn('source_handler_id', $sourceIds)
            ->where('movement_type', $movement->value)
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $categoryId))
            ->get()
            ->sortBy(fn (GrievanceRoute $r) => [
                $r->category_id !== null ? 0 : 1,
                array_search($r->source_handler_id, $sourceIds, true),
                $r->priority,
            ])
            ->values();
    }

    /**
     * First handler for a newly accepted case: the most specific approved
     * INITIAL_ASSIGNMENT route from the complainant's organization (or an
     * ancestor route that applies to descendants). Without one, the
     * organization's own approved grievance committee, if exactly one exists.
     *
     * @return array{type: GrievanceHandlerType, id: string, route: GrievanceRoute|null}|null
     */
    public function resolveInitialHandler(Grievance $grievance): ?array
    {
        $organizations = $this->handlers->organizationWithAncestors($grievance->organization_id);
        $routes = $this->routesFrom(GrievanceHandlerType::Organization, $organizations, GrievanceMovementType::InitialAssignment, $grievance->category_id)
            ->filter(fn (GrievanceRoute $r) => $r->source_handler_id === $grievance->organization_id || $r->include_descendants);

        foreach ($routes as $route) {
            if ($this->handlers->availabilityProblems($route->target_handler_type, $route->target_handler_id) === []) {
                return ['type' => $route->target_handler_type, 'id' => $route->target_handler_id, 'route' => $route];
            }
        }

        $committees = GrievanceCommittee::query()
            ->where('organization_id', $grievance->organization_id)
            ->where('committee_type', CommitteeType::Grievance->value)
            ->where('status', 'active')
            ->get()
            ->filter(fn (GrievanceCommittee $c) => $this->handlers->committeeProblems($c) === []);

        if ($grievance->organization_unit_id !== null && $committees->count() > 1) {
            $unitMatch = $committees->where('organization_unit_id', $grievance->organization_unit_id);
            $committees = $unitMatch->isNotEmpty() ? $unitMatch : $committees->whereNull('organization_unit_id');
        }

        return $committees->count() === 1
            ? ['type' => GrievanceHandlerType::Committee, 'id' => $committees->first()->getKey(), 'route' => null]
            : null;
    }

    /**
     * Next handler from a stage. An appeal with no appeal-specific route and
     * a manual escalation with no manual route both fall back to the
     * configured timeout-escalation route (the "next level").
     *
     * @return list<array{type: GrievanceHandlerType, id: string, route: GrievanceRoute}>
     */
    public function nextHandlers(GrievanceCaseStage $stage, GrievanceMovementType $movement, ?string $categoryId): array
    {
        $routes = $this->routesFrom($stage->handler_type, [(string) $stage->handler_id], $movement, $categoryId);
        if ($routes->isEmpty() && in_array($movement, [GrievanceMovementType::EmployeeAppeal, GrievanceMovementType::ManualEscalation], true)) {
            $routes = $this->routesFrom($stage->handler_type, [(string) $stage->handler_id], GrievanceMovementType::TimeoutEscalation, $categoryId);
        }

        $options = [];
        foreach ($routes as $route) {
            if ($this->handlers->availabilityProblems($route->target_handler_type, $route->target_handler_id) === []) {
                $options[] = ['type' => $route->target_handler_type, 'id' => (string) $route->target_handler_id, 'route' => $route];
            }
        }

        return $options;
    }

    /**
     * A user-chosen route must be one of the valid options (never an
     * arbitrary handler).
     *
     * @return array{type: GrievanceHandlerType, id: string, route: GrievanceRoute}
     */
    public function validateChoice(GrievanceCaseStage $stage, GrievanceMovementType $movement, ?string $categoryId, ?string $routeId): array
    {
        $options = $this->nextHandlers($stage, $movement, $categoryId);
        foreach ($options as $option) {
            if ($routeId === null || $option['route']->getKey() === $routeId) {
                return $option;
            }
        }

        throw ValidationException::withMessages(['route_id' => __('grievances.errors.no_valid_route')]);
    }

    /**
     * Move the case to a new stage. MUST run inside the caller's transaction
     * with the grievance row locked. The previous stage (if any) is closed,
     * the new one gets its SLA snapshot and (for a committee) its panel.
     */
    public function createStage(
        Grievance $grievance,
        GrievanceHandlerType $type,
        string $handlerId,
        GrievanceMovementType $movement,
        ?GrievanceRoute $route = null,
        ?GrievanceCaseStage $from = null,
        ?string $reason = null,
        ?User $actor = null,
    ): GrievanceCaseStage {
        if ($type === GrievanceHandlerType::Organization) {
            throw ValidationException::withMessages(['route' => __('grievances.errors.handler_invalid')]);
        }
        $problems = $this->handlers->availabilityProblems($type, $handlerId);
        if ($problems !== []) {
            throw ValidationException::withMessages(['route' => __($problems[0])]);
        }

        $now = now();
        if ($from !== null) {
            $from->forceFill([
                'is_current' => false,
                'status' => self::CLOSING_STATUS[$movement->value] ?? GrievanceStageStatus::Closed,
                'completed_at' => $from->completed_at ?? $now,
                'escalated_at' => in_array($movement, [GrievanceMovementType::TimeoutEscalation, GrievanceMovementType::ManualEscalation], true) ? $now : $from->escalated_at,
            ])->save();
        } else {
            // Defensive: never two current stages (the partial unique index enforces it too).
            GrievanceCaseStage::query()->where('grievance_id', $grievance->getKey())->where('is_current', true)
                ->update(['is_current' => false, 'status' => GrievanceStageStatus::Closed->value, 'completed_at' => $now]);
        }

        $handler = $this->handlers->find($type, $handlerId);
        $stage = new GrievanceCaseStage([
            'grievance_id' => $grievance->getKey(),
            'stage_no' => (int) GrievanceCaseStage::query()->where('grievance_id', $grievance->getKey())->max('stage_no') + 1,
            'handler_type' => $type,
            'handler_id' => $handlerId,
            'organization_id' => $this->handlers->organizationIdOf($type, $handlerId),
            'organization_unit_id' => match (true) {
                $handler instanceof OrganizationUnit => $handler->getKey(),
                $handler instanceof GrievanceCommittee => $handler->organization_unit_id,
                default => null,
            },
            'committee_id' => $handler instanceof GrievanceCommittee ? $handler->getKey() : null,
            'external_authority_id' => $handler instanceof GrievanceExternalAuthority ? $handler->getKey() : null,
            'route_id' => $route?->getKey(),
            'from_stage_id' => $from?->getKey(),
            'movement_type' => $movement,
            'movement_reason' => $reason,
            'moved_by' => $actor?->getKey(),
            'status' => GrievanceStageStatus::Pending,
            'is_current' => true,
        ]);

        $profile = $route?->slaProfile ?? $this->sla->profileFor(GrievanceSlaPurpose::Resolution, $type, $handlerId, $stage->organization_id, $grievance->category_id);
        $this->sla->snapshot($stage, $profile);
        $stage->save();

        if ($handler instanceof GrievanceCommittee) {
            $this->snapshotPanel($stage, $handler, $actor);
        }

        $caseStatus = match (true) {
            $type === GrievanceHandlerType::ExternalAuthority => GrievanceStatus::ReferredExternal,
            $movement === GrievanceMovementType::EmployeeAppeal => GrievanceStatus::Appealed,
            default => GrievanceStatus::UnderReview,
        };
        $grievance->forceFill([
            'current_stage_id' => $stage->getKey(),
            'current_handler_type' => $type,
            'current_handler_id' => $handlerId,
            'status' => $caseStatus,
        ])->save();

        if ($handler instanceof GrievanceExternalAuthority && $handler->is_administrative_tribunal) {
            // Keeps the existing Administrative Tribunal register in step.
            AdministrativeTribunalCase::query()->firstOrCreate(
                ['grievance_id' => $grievance->getKey()],
                ['case_number' => 'TRB-'.$grievance->reference_number, 'status' => 'open', 'created_by_user_id' => $actor?->getKey()],
            );
        }

        $described = $this->handlers->describe($type, $handlerId);
        $this->audit->record(AuditEventType::GrievanceStageCreated, $actor, $grievance, [
            'stage_id' => $stage->getKey(),
            'stage_no' => $stage->stage_no,
            'movement' => $movement->value,
            'handler_type' => $type->value,
            'handler_id' => $handlerId,
            'route_id' => $route?->getKey(),
            'from_stage_id' => $from?->getKey(),
            'due_at' => $stage->due_at?->toIso8601String(),
        ], null, $reason);

        $event = match ($movement) {
            GrievanceMovementType::InitialAssignment => 'accepted',
            GrievanceMovementType::TimeoutEscalation => 'auto_escalated',
            GrievanceMovementType::ManualEscalation => 'escalated',
            GrievanceMovementType::EmployeeAppeal => 'appealed',
            GrievanceMovementType::Referred => 'referred',
            default => 'stage_created',
        };
        $this->timeline->record($grievance, $event, $actor, [
            'handler' => ['type' => $type->value, 'name_en' => $described['name_en'], 'name_am' => $described['name_am']],
            'stage_no' => $stage->stage_no,
            'due_at' => $stage->due_at?->toIso8601String(),
        ], $stage->getKey());

        $this->notifier->toStageHandlers($stage->setRelation('grievance', $grievance), 'new_assignment', $actor);
        if ($movement !== GrievanceMovementType::InitialAssignment) {
            $this->notifier->toComplainant($grievance, $movement === GrievanceMovementType::TimeoutEscalation ? 'auto_escalated' : 'case_moved');
        }

        return $stage;
    }

    /** Current committee members become the stage panel (history is kept per stage). */
    public function snapshotPanel(GrievanceCaseStage $stage, GrievanceCommittee $committee, ?User $actor): void
    {
        foreach ($committee->members()->servingOn(now())->get() as $member) {
            GrievanceStageMember::query()->firstOrCreate(
                ['case_stage_id' => $stage->getKey(), 'employee_id' => $member->employee_id],
                [
                    'committee_member_id' => $member->getKey(),
                    'role' => $member->roleEnum(),
                    'source' => 'committee',
                    'is_active' => true,
                    'joined_at' => now(),
                    'added_by' => $actor?->getKey(),
                ],
            );
        }
    }

    /**
     * Committee membership changed: open stages of that committee follow
     * (a leaving member leaves the panel; a new member joins). History rows
     * are never deleted.
     */
    public function syncOpenPanels(GrievanceCommittee $committee, ?User $actor): void
    {
        DB::transaction(function () use ($committee, $actor): void {
            $serving = $committee->members()->servingOn(now())->get()->keyBy('employee_id');
            $stages = GrievanceCaseStage::query()->where('committee_id', $committee->getKey())->open()->get();

            foreach ($stages as $stage) {
                foreach ($stage->members()->where('source', 'committee')->where('is_active', true)->get() as $seat) {
                    if (! $serving->has($seat->employee_id)) {
                        $seat->forceFill(['is_active' => false, 'left_at' => now()])->save();
                    }
                }
                foreach ($serving as $employeeId => $member) {
                    $seat = $stage->members()->where('employee_id', $employeeId)->first();
                    if ($seat === null) {
                        GrievanceStageMember::query()->create([
                            'case_stage_id' => $stage->getKey(), 'employee_id' => $employeeId, 'committee_member_id' => $member->getKey(),
                            'role' => $member->roleEnum(), 'source' => 'committee', 'is_active' => true, 'joined_at' => now(), 'added_by' => $actor?->getKey(),
                        ]);
                    } elseif ($seat->recused_at === null && ! $seat->is_active && $seat->source === 'committee') {
                        $seat->forceFill(['is_active' => true, 'left_at' => null, 'role' => $member->roleEnum()])->save();
                    } elseif ($seat->is_active && $seat->role !== $member->roleEnum()) {
                        $seat->forceFill(['role' => $member->roleEnum()])->save();
                    }
                }
            }
        });
    }
}
