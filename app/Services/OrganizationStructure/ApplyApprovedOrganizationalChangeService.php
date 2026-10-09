<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructure;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\OrganizationalChangeAction;
use App\Enums\OrganizationalChangeEntityType;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestItem;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionMovement;
use App\Models\User;
use App\Services\OrganizationalChange\ChangeRequestConflictDetector;
use App\Services\OrganizationalChange\ChangeRequestHistoryRecorder;
use App\Services\OrganizationalChange\ChangeRequestImpactAnalyzer;
use App\Services\OrganizationalChange\ChangeRequestNotifier;
use App\Services\OrganizationalChange\ChangeRequestPayloadValidator;
use App\Services\OrganizationalChange\ChangeRequestScopeService;
use App\Services\OrganizationalChange\ImplementingUnitResolver;
use App\Services\OrganizationStructure\Exceptions\ImplementationBlockedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one controlled path from an approved request to changed master data.
 *
 * This service is deliberately the only writer for approved organizational
 * changes. It does not call the ordinary CreatePosition / MovePosition actions
 * because those re-authorise against master-data permissions (positions.move
 * and friends), whereas an implementer acts under
 * organizational-change-requests.implement plus the approval itself. Routing the
 * write through here keeps one transaction, one lock, one revalidation and one
 * audit trail.
 *
 * It applies ONLY the frozen approved payload. The implementer supplies no
 * values: quantity, grade, unit, parent, title and effective date all come
 * from what the approver signed off, and a payload whose fingerprint no longer
 * matches is refused outright.
 */
final readonly class ApplyApprovedOrganizationalChangeService
{
    public function __construct(
        private ChangeRequestConflictDetector $conflictDetector,
        private ChangeRequestHistoryRecorder $history,
        private ChangeRequestImpactAnalyzer $impactAnalyzer,
        private ChangeRequestPayloadValidator $payloadValidator,
        private ChangeRequestScopeService $scope,
        private ChangeRequestNotifier $notifier,
        private ImplementingUnitResolver $implementingUnitResolver,
        private WriteAuditLogAction $audit,
        private GenerateCodeAction $generateCode,
    ) {}

    /**
     * Apply an approved request.
     *
     * @throws ValidationException when the request is not in an applicable state
     * @throws ImplementationBlockedException when master data has drifted since approval
     */
    public function execute(
        OrganizationalChangeRequest $request,
        User $implementer,
        ?string $note = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        // (2) and (3): who may act, and are they the assigned implementer?
        if (! $this->implementingUnitResolver->canImplement($implementer, $request, $this->scope)) {
            abort(403, __('organizational-change-requests.errors.not_authorized_to_implement'));
        }

        // (1) + double-implementation guard: claim the request atomically.
        // Only one process can move PendingImplementation/Approved -> Implementing.
        $claimed = OrganizationalChangeRequest::query()
            ->whereKey($request->getKey())
            ->whereIn('status', [
                OrganizationalChangeRequestStatus::Approved->value,
                OrganizationalChangeRequestStatus::PendingImplementation->value,
            ])
            ->update([
                'status' => OrganizationalChangeRequestStatus::Implementing->value,
                'implementation_started_at' => now(),
                'implementation_claimed_by' => $implementer->getKey(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.not_claimable'),
            ]);
        }

        $request->refresh();

        $this->history->record(
            request: $request,
            actor: $implementer,
            action: 'implementation_claimed',
            from: OrganizationalChangeRequestStatus::PendingImplementation,
            to: OrganizationalChangeRequestStatus::Implementing,
            auditEvent: AuditEventType::OrganizationalChangeRequestImplementationClaimed,
            httpRequest: $httpRequest,
        );

        try {
            return DB::transaction(function () use ($request, $implementer, $note, $httpRequest): OrganizationalChangeRequest {
                $locked = OrganizationalChangeRequest::query()
                    ->lockForUpdate()
                    ->with('items')
                    ->findOrFail($request->getKey());

                // (5) the approved payload must be byte-identical to what was signed off.
                $this->assertPayloadUnmodified($locked);

                // (4) revalidate live master data.
                $conflicts = $this->conflictDetector->detect($locked);

                if ($conflicts !== []) {
                    throw new ImplementationBlockedException($conflicts);
                }

                /*
                 * Snapshot the impact BEFORE anything is written. Taken after
                 * the writes it would describe the post-change world, which
                 * makes figures like "expected total after approval"
                 * meaningless — they would be measured against the very rows
                 * the change just created.
                 */
                $impactBefore = $this->impactAnalyzer->analyze($locked);

                // (6) + (7) apply only approved values.
                $results = [];

                foreach ($locked->items as $item) {
                    $results[] = $this->applyItem($locked, $item, $implementer, $httpRequest);
                }

                $now = now();

                $locked->forceFill([
                    'status' => OrganizationalChangeRequestStatus::Implemented->value,
                    'implemented_at' => $now,
                    'implemented_by' => $implementer->getKey(),
                    'implementation_note' => $note,
                    'implementation_result' => [
                        'items' => $results,
                        // What the implementer was deciding against...
                        'impact_before_implementation' => $impactBefore,
                        // ...and the state the change left behind.
                        'impact_after_implementation' => $this->impactAnalyzer->analyze($locked->fresh(['items'])),
                        'applied_at' => $now->toDateTimeString(),
                    ],
                    'blocked_reasons' => null,
                    'blocked_at' => null,
                ])->save();

                // (8) + (9) request history and audit.
                $this->history->record(
                    request: $locked,
                    actor: $implementer,
                    action: 'implemented',
                    from: OrganizationalChangeRequestStatus::Implementing,
                    to: OrganizationalChangeRequestStatus::Implemented,
                    comment: $note,
                    context: ['results' => $results],
                    auditEvent: AuditEventType::OrganizationalChangeRequestImplemented,
                    httpRequest: $httpRequest,
                );

                $this->notifier->implemented($locked);

                return $locked;
            });
        } catch (ImplementationBlockedException $exception) {
            // Nothing was applied: the transaction rolled back. Park the
            // request so an authorised decision can be taken.
            $this->markBlocked($request, $implementer, $exception->conflicts, $httpRequest);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->markBlocked($request, $implementer, [[
                'code' => 'implementation_error',
                'context' => ['message' => $exception->getMessage()],
            ]], $httpRequest);

            throw $exception;
        }
    }

    /**
     * (11) Close the request once the implementing unit confirms the change
     * has taken effect. Kept separate from implementation so a supervisor can
     * sign off on the applied result.
     */
    public function complete(
        OrganizationalChangeRequest $request,
        User $actor,
        ?string $note = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if ($request->status !== OrganizationalChangeRequestStatus::Implemented) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.not_completable'),
            ]);
        }

        $request->forceFill([
            'status' => OrganizationalChangeRequestStatus::Completed->value,
            'completed_at' => now(),
            'completed_by' => $actor->getKey(),
        ])->save();

        $this->history->record(
            request: $request,
            actor: $actor,
            action: 'completed',
            from: OrganizationalChangeRequestStatus::Implemented,
            to: OrganizationalChangeRequestStatus::Completed,
            comment: $note,
            auditEvent: AuditEventType::OrganizationalChangeRequestCompleted,
            httpRequest: $httpRequest,
        );

        $this->notifier->completed($request);

        return $request;
    }

    // ── Applying one item ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function applyItem(
        OrganizationalChangeRequest $request,
        OrganizationalChangeRequestItem $item,
        User $implementer,
        ?Request $httpRequest,
    ): array {
        return match ($item->entity_type) {
            OrganizationalChangeEntityType::OrganizationUnit => $this->applyUnitItem($request, $item, $implementer, $httpRequest),
            OrganizationalChangeEntityType::Position => $this->applyPositionItem($request, $item, $implementer, $httpRequest),
            // A narrative request records its decision; there is no master-data write.
            OrganizationalChangeEntityType::Narrative => [
                'entity_type' => $item->entity_type->value,
                'action' => $item->action->value,
                'applied' => false,
                'note' => 'narrative_request_no_master_data_change',
            ],
        };
    }

    /** @return array<string, mixed> */
    private function applyUnitItem(
        OrganizationalChangeRequest $request,
        OrganizationalChangeRequestItem $item,
        User $implementer,
        ?Request $httpRequest,
    ): array {
        $proposed = $item->proposed_data ?? [];

        if ($item->action === OrganizationalChangeAction::Create) {
            $attributes = [
                'organization_id' => $request->organization_id,
                'parent_unit_id' => $proposed['parent_unit_id'] ?? null,
                'organization_unit_type_id' => $proposed['organization_unit_type_id'] ?? null,
                // unit_type is a required free-text classifier. When the
                // requester chose a unit type but no explicit code, derive it
                // from that type so the column is never left null.
                'unit_type' => $proposed['unit_type']
                    ?? $this->deriveUnitType($proposed['organization_unit_type_id'] ?? null),
                'name_en' => $proposed['name_en'] ?? null,
                'name_am' => $proposed['name_am'] ?? null,
                'description_en' => $proposed['description_en'] ?? null,
                'description_am' => $proposed['description_am'] ?? null,
                'status' => OrganizationUnitStatus::Active->value,
                'effective_from' => $proposed['effective_from'] ?? $request->requested_effective_date?->toDateString(),
                'sort_order' => (int) ($proposed['sort_order'] ?? 0),
                'created_by' => $implementer->getKey(),
                'updated_by' => $implementer->getKey(),
                'metadata' => $this->provenance($request),
            ];

            $attributes['code'] = $this->generateCode->execute(
                CodeRuleEntityType::OrganizationUnit,
                [
                    'organization_id' => $attributes['organization_id'],
                    'organization_unit_type_id' => $attributes['organization_unit_type_id'],
                ],
                $implementer,
                null,
                'code',
            );

            $unit = OrganizationUnit::query()->create($attributes);

            $this->audit->execute(
                AuditEventType::OrganizationUnitCreated,
                $implementer,
                $unit,
                $unit->organization_id,
                newValues: $unit->toArray(),
                reason: $this->provenanceReason($request),
                request: $httpRequest,
            );

            $item->forceFill(['resulting_entity_id' => $unit->getKey()])->save();

            return [
                'entity_type' => 'organization_unit',
                'action' => 'create',
                'applied' => true,
                'resulting_entity_id' => (string) $unit->getKey(),
                'code' => $unit->code,
            ];
        }

        $unit = OrganizationUnit::query()->lockForUpdate()->findOrFail($item->entity_id);
        $before = $unit->toArray();

        $updates = ['updated_by' => $implementer->getKey()];

        foreach (['name_en', 'name_am', 'description_en', 'description_am', 'organization_unit_type_id', 'unit_type', 'parent_unit_id'] as $field) {
            if (array_key_exists($field, $proposed)) {
                $updates[$field] = $proposed[$field];
            }
        }

        if (array_key_exists('status', $proposed)) {
            $updates['status'] = $proposed['status'];
        }

        if (array_key_exists('effective_from', $proposed) && $proposed['effective_from'] !== null) {
            $updates['effective_from'] = $proposed['effective_from'];
        }

        if (array_key_exists('effective_to', $proposed) && $proposed['effective_to'] !== null) {
            $updates['effective_to'] = $proposed['effective_to'];
        }

        // Functional relationships are recorded on the unit's metadata; the
        // structural parent stays the authoritative tree edge.
        if (array_key_exists('functional_parent_unit_id', $proposed)) {
            $metadata = is_array($unit->metadata) ? $unit->metadata : [];
            $metadata['functional_parent_unit_id'] = $proposed['functional_parent_unit_id'];
            $metadata['functional_relationship_note'] = $proposed['relationship_note'] ?? null;
            $updates['metadata'] = array_merge($metadata, $this->provenance($request));
        }

        $unit->forceFill($updates)->save();

        $event = match ($item->action) {
            OrganizationalChangeAction::Deactivate => AuditEventType::OrganizationUnitArchived,
            default => AuditEventType::OrganizationUnitUpdated,
        };

        $this->audit->execute(
            $event,
            $implementer,
            $unit,
            $unit->organization_id,
            oldValues: $before,
            newValues: $unit->toArray(),
            reason: $this->provenanceReason($request),
            request: $httpRequest,
        );

        $item->forceFill(['resulting_entity_id' => $unit->getKey()])->save();

        return [
            'entity_type' => 'organization_unit',
            'action' => $item->action->value,
            'applied' => true,
            'resulting_entity_id' => (string) $unit->getKey(),
            'changed_fields' => array_keys($updates),
        ];
    }

    /** @return array<string, mixed> */
    private function applyPositionItem(
        OrganizationalChangeRequest $request,
        OrganizationalChangeRequestItem $item,
        User $implementer,
        ?Request $httpRequest,
    ): array {
        $proposed = $item->proposed_data ?? [];

        if ($item->action === OrganizationalChangeAction::Create) {
            return $this->createPositions($request, $item, $proposed, $implementer, $httpRequest);
        }

        $position = Position::query()->lockForUpdate()->findOrFail($item->entity_id);
        $before = $position->toArray();

        if ($item->action === OrganizationalChangeAction::Increase) {
            return $this->createPositions(
                $request,
                $item,
                [
                    'organization_unit_id' => $position->organization_unit_id,
                    'title_en' => $position->title_en,
                    'title_am' => $position->title_am,
                    'occupation_id' => $position->occupation_id,
                    'grade_level' => $position->grade_level,
                    'job_family' => $position->job_family,
                    'description_en' => $position->description_en,
                    'description_am' => $position->description_am,
                    'quantity' => (int) ($proposed['additional_quantity'] ?? 0),
                    'effective_from' => $proposed['effective_from'] ?? null,
                ],
                $implementer,
                $httpRequest,
                clonedFrom: (string) $position->getKey(),
            );
        }

        $updates = [];

        foreach (['title_en', 'title_am', 'description_en', 'description_am', 'occupation_id', 'job_family', 'grade_level', 'organization_unit_id'] as $field) {
            if (array_key_exists($field, $proposed)) {
                $updates[$field] = $proposed[$field];
            }
        }

        if (array_key_exists('is_active', $proposed)) {
            $updates['is_active'] = (bool) $proposed['is_active'];
        }

        if (! empty($proposed['effective_from'])) {
            $updates['effective_from'] = $proposed['effective_from'];
        }

        if (! empty($proposed['effective_to'])) {
            $updates['effective_to'] = $proposed['effective_to'];
        }

        /*
         * The position code is identity, not a field. A move or a grade change
         * never regenerates it; regeneration is a separately authorised
         * operation elsewhere in the system.
         */
        unset($updates['code'], $updates['job_position_code']);

        $movement = null;

        if ($item->action === OrganizationalChangeAction::Move) {
            $fromUnitId = $position->organization_unit_id;
            $toUnitId = $proposed['organization_unit_id'] ?? null;

            $position->forceFill($updates)->save();

            // (8) master-data history, using the existing position architecture.
            $movement = PositionMovement::query()->create([
                'position_id' => $position->getKey(),
                'organization_id' => $position->organization_id,
                'from_organization_unit_id' => $fromUnitId,
                'to_organization_unit_id' => $toUnitId,
                'moved_by' => $implementer->getKey(),
                'reason' => $this->provenanceReason($request),
                'moved_at' => now(),
            ]);
        } else {
            $position->forceFill($updates)->save();
        }

        if ($item->action === OrganizationalChangeAction::Abolish) {
            $position->forceFill([
                'deleted_by' => $implementer->getKey(),
                'deletion_reason' => $this->provenanceReason($request),
            ])->save();
            $position->delete();
        }

        $event = match ($item->action) {
            OrganizationalChangeAction::Move => AuditEventType::PositionMoved,
            OrganizationalChangeAction::Abolish => AuditEventType::PositionArchived,
            default => AuditEventType::PositionUpdated,
        };

        $this->audit->execute(
            $event,
            $implementer,
            $position,
            $position->organization_id,
            oldValues: $before,
            newValues: $position->fresh()?->toArray(),
            reason: $this->provenanceReason($request),
            request: $httpRequest,
        );

        $item->forceFill(['resulting_entity_id' => $position->getKey()])->save();

        return array_filter([
            'entity_type' => 'position',
            'action' => $item->action->value,
            'applied' => true,
            'resulting_entity_id' => (string) $position->getKey(),
            'code_preserved' => $position->job_position_code,
            'movement_id' => $movement?->getKey(),
            'changed_fields' => array_keys($updates),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Create the approved number of positions, each with its own generated
     * code. Quantity comes from the approved payload only.
     *
     * @return array<string, mixed>
     */
    private function createPositions(
        OrganizationalChangeRequest $request,
        OrganizationalChangeRequestItem $item,
        array $proposed,
        User $implementer,
        ?Request $httpRequest,
        ?string $clonedFrom = null,
    ): array {
        $quantity = max(1, (int) ($proposed['quantity'] ?? 1));
        $created = [];

        for ($index = 0; $index < $quantity; $index++) {
            $attributes = [
                'organization_id' => $request->organization_id,
                'organization_unit_id' => $proposed['organization_unit_id'] ?? null,
                'occupation_id' => $proposed['occupation_id'] ?? null,
                'title_en' => $proposed['title_en'] ?? null,
                'title_am' => $proposed['title_am'] ?? null,
                'description_en' => $proposed['description_en'] ?? null,
                'description_am' => $proposed['description_am'] ?? null,
                'grade_level' => $proposed['grade_level'] ?? null,
                'job_family' => $proposed['job_family'] ?? null,
                'is_active' => (bool) ($proposed['is_active'] ?? true),
                'effective_from' => $proposed['effective_from'] ?? $request->requested_effective_date?->toDateString(),
                'metadata' => $this->provenance($request, $clonedFrom),
            ];

            $attributes['job_position_code'] = $this->generateCode->execute(
                CodeRuleEntityType::Position,
                [
                    'organization_id' => $attributes['organization_id'],
                    'organization_unit_id' => $attributes['organization_unit_id'],
                ],
                $implementer,
                null,
                'job_position_code',
            );

            $position = Position::query()->create($attributes);

            $this->audit->execute(
                AuditEventType::PositionCreated,
                $implementer,
                $position,
                $position->organization_id,
                newValues: $position->toArray(),
                reason: $this->provenanceReason($request),
                request: $httpRequest,
            );

            $created[] = [
                'id' => (string) $position->getKey(),
                'job_position_code' => $position->job_position_code,
            ];
        }

        if ($created !== []) {
            $item->forceFill(['resulting_entity_id' => $created[0]['id']])->save();
        }

        return [
            'entity_type' => 'position',
            'action' => $item->action->value,
            'applied' => true,
            'created_count' => count($created),
            'created' => $created,
            'cloned_from' => $clonedFrom,
        ];
    }

    // ── Guards and helpers ──────────────────────────────────────────────────

    /**
     * The approved payload was fingerprinted at approval. If the stored items
     * no longer hash to that value, someone edited the proposal after sign-off
     * and the change must not be applied.
     */
    private function assertPayloadUnmodified(OrganizationalChangeRequest $request): void
    {
        $expected = $request->approved_payload_hash;

        if ($expected === null) {
            throw ValidationException::withMessages([
                'approved_payload' => __('organizational-change-requests.errors.missing_approved_payload'),
            ]);
        }

        if (! hash_equals($expected, self::fingerprint($request))) {
            throw new ImplementationBlockedException([[
                'code' => 'approved_payload_modified',
                'context' => ['request_no' => $request->request_no],
            ]]);
        }
    }

    /**
     * Stable fingerprint of the proposal. Computed at approval and re-computed
     * before implementation; any drift means the payload changed.
     */
    public static function fingerprint(OrganizationalChangeRequest $request): string
    {
        $items = $request->items
            ->sortBy('sort_order')
            ->map(static fn (OrganizationalChangeRequestItem $item): array => [
                'entity_type' => $item->entity_type->value,
                'entity_id' => $item->entity_id,
                'action' => $item->action->value,
                'proposed_data' => $item->proposed_data,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'request_type' => $request->request_type->value,
            'organization_id' => $request->organization_id,
            'requested_effective_date' => $request->requested_effective_date?->toDateString(),
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array{code: string, context: array<string, mixed>}>  $conflicts
     */
    private function markBlocked(
        OrganizationalChangeRequest $request,
        User $implementer,
        array $conflicts,
        ?Request $httpRequest,
    ): void {
        $request->refresh();

        $request->forceFill([
            'status' => OrganizationalChangeRequestStatus::ImplementationBlocked->value,
            'blocked_reasons' => $conflicts,
            'blocked_at' => now(),
            'implementation_started_at' => null,
            'implementation_claimed_by' => null,
        ])->save();

        $this->history->record(
            request: $request,
            actor: $implementer,
            action: 'implementation_blocked',
            from: OrganizationalChangeRequestStatus::Implementing,
            to: OrganizationalChangeRequestStatus::ImplementationBlocked,
            context: ['conflicts' => $conflicts],
            auditEvent: AuditEventType::OrganizationalChangeRequestImplementationBlocked,
            httpRequest: $httpRequest,
        );

        $this->notifier->implementationBlocked($request, $conflicts);
    }

    /**
     * Resolve the free-text unit_type classifier from the selected unit type.
     * Falls back to a neutral value so the NOT NULL column is always filled.
     */
    private function deriveUnitType(?string $unitTypeId): string
    {
        if ($unitTypeId !== null) {
            $code = OrganizationUnitType::query()->whereKey($unitTypeId)->value('code');

            if (is_string($code) && $code !== '') {
                return strtolower($code);
            }
        }

        return 'unit';
    }

    /** @return array<string, mixed> */
    private function provenance(OrganizationalChangeRequest $request, ?string $clonedFrom = null): array
    {
        return array_filter([
            'created_via_change_request' => $request->request_no,
            'change_request_id' => (string) $request->getKey(),
            'cloned_from_position_id' => $clonedFrom,
        ], static fn ($value): bool => $value !== null);
    }

    private function provenanceReason(OrganizationalChangeRequest $request): string
    {
        return __('organizational-change-requests.applied_via_request', ['number' => $request->request_no]);
    }
}
