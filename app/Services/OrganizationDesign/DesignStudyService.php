<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Actions\OrganizationalChange\SaveChangeRequestAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Design studies and their unit proposals (brief §35–36, 112–113).
 *
 * The study is the workspace: while it is a draft, proposals and their
 * responsibilities change; submitted, it waits for a reviewer; approved by
 * someone other than its preparer, each proposal is handed to the
 * organizational change-request pipeline as one request (D-1 = b). Nothing
 * here touches organization_units directly: implementation stays with the
 * pipeline, and a proposal adopts the unit the pipeline created.
 */
class DesignStudyService
{
    public const FIELDS = ['title_en', 'title_am', 'study_type', 'reason', 'scope_notes', 'started_at', 'target_effective_date', 'hierarchy_version_id'];

    public const PROPOSAL_FIELDS = [
        'proposal_type', 'existing_unit_id', 'parent_proposed_unit_id', 'parent_existing_unit_id', 'proposed_unit_type_id', 'unit_type',
        'name_en', 'name_am', 'description_en', 'description_am', 'design_rationale', 'effective_from', 'effective_to', 'sequence',
    ];

    private const PREFIX = 'ODS';

    public function __construct(private readonly OrganizationDesignAudit $audit, private readonly SaveChangeRequestAction $changeRequests) {}

    // ── Study lifecycle ──────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function create(User $actor, string $organizationId, array $data): OrganizationDesignStudy
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): OrganizationDesignStudy {
            $study = new OrganizationDesignStudy([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $organizationId,
                'reference_number' => $this->nextReference(),
                'status' => OrganizationDesignStatus::Draft,
                'started_at' => $data['started_at'] ?? now()->toDateString(),
                'prepared_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $study->save();
            $this->audit->record(AuditEventType::DesignStudyCreated, $actor, $study, $study->only([...self::FIELDS, 'reference_number']));
            $this->audit->transition($actor, $study, null, OrganizationDesignStatus::Draft->value);

            return $study;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, OrganizationDesignStudy $study, array $data): OrganizationDesignStudy
    {
        $this->assertEditable($study);
        $old = $study->only(self::FIELDS);
        $study->fill([...array_intersect_key($data, array_flip(self::FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::DesignStudyUpdated, $actor, $study, $study->only(self::FIELDS), $old);

        return $study;
    }

    public function submit(User $actor, OrganizationDesignStudy $study): OrganizationDesignStudy
    {
        $this->assertEditable($study);
        if (! $study->proposals()->exists()) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_needs_proposals')]);
        }
        $study->forceFill(['status' => OrganizationDesignStatus::UnderReview, 'submitted_at' => now(), 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $study, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value);

        return $study;
    }

    /** Hand the study back to its preparer with a reason. */
    public function returnToDraft(User $actor, OrganizationDesignStudy $study, ?string $reason): OrganizationDesignStudy
    {
        if ($study->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_under_review')]);
        }
        $study->forceFill(['status' => OrganizationDesignStatus::Draft, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_comment' => $reason, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $study, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value, $reason);

        return $study;
    }

    public function approve(User $actor, OrganizationDesignStudy $study): OrganizationDesignStudy
    {
        if ($study->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_under_review')]);
        }
        if ($study->prepared_by !== null && (int) $study->prepared_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_approve_same_person')]);
        }

        return DB::transaction(function () use ($actor, $study): OrganizationDesignStudy {
            $study->forceFill(['status' => OrganizationDesignStatus::Approved, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            UnitDesignProposal::query()->where('study_id', $study->getKey())->where('status', 'proposed')->update(['status' => 'accepted']);
            $this->audit->record(AuditEventType::DesignStudyApproved, $actor, $study, ['proposals' => $study->proposals()->count()]);
            $this->audit->transition($actor, $study, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value);

            return $study;
        });
    }

    public function cancel(User $actor, OrganizationDesignStudy $study, ?string $reason): OrganizationDesignStudy
    {
        if (! in_array($study->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_cancellable')]);
        }
        $from = $study->status->value;
        $study->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $study, $from, OrganizationDesignStatus::Cancelled->value, $reason);

        return $study;
    }

    // ── Proposals ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createProposal(User $actor, OrganizationDesignStudy $study, array $data): UnitDesignProposal
    {
        $this->assertEditable($study);
        $this->validateProposal($study, $data, null);
        if (blank($data['name_en'] ?? null) && ! empty($data['existing_unit_id'])) {
            $data['name_en'] = (string) OrganizationUnit::query()->whereKey($data['existing_unit_id'])->value('name_en');
        }
        $proposal = new UnitDesignProposal([
            ...array_intersect_key($data, array_flip(self::PROPOSAL_FIELDS)),
            'study_id' => $study->getKey(), 'organization_id' => $study->organization_id, 'status' => 'proposed',
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
        ]);
        $proposal->save();
        $this->audit->record(AuditEventType::UnitProposalSaved, $actor, $proposal, $proposal->only(self::PROPOSAL_FIELDS));

        return $proposal;
    }

    /** @param array<string, mixed> $data */
    public function updateProposal(User $actor, UnitDesignProposal $proposal, array $data): UnitDesignProposal
    {
        $study = $proposal->study;
        $this->assertEditable($study);
        $merged = [...$proposal->only(self::PROPOSAL_FIELDS), ...array_intersect_key($data, array_flip(self::PROPOSAL_FIELDS))];
        $this->validateProposal($study, $merged, $proposal);
        $old = $proposal->only(self::PROPOSAL_FIELDS);
        $proposal->fill([...array_intersect_key($data, array_flip(self::PROPOSAL_FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::UnitProposalSaved, $actor, $proposal, $proposal->only(self::PROPOSAL_FIELDS), $old);

        return $proposal;
    }

    public function removeProposal(User $actor, UnitDesignProposal $proposal): void
    {
        $this->assertEditable($proposal->study);
        if ($proposal->children()->exists()) {
            throw ValidationException::withMessages(['proposal' => __('organization-design.proposal_has_children')]);
        }
        DB::transaction(function () use ($actor, $proposal): void {
            UnitResponsibility::query()->where('unit_design_proposal_id', $proposal->getKey())->delete();
            $this->audit->record(AuditEventType::UnitProposalRemoved, $actor, $proposal, [], $proposal->only(self::PROPOSAL_FIELDS));
            $proposal->delete();
        });
    }

    // ── Hand-over to the change-request pipeline ─────────────────────────

    /**
     * One organizational change request per accepted proposal whose parent
     * already exists. Proposals under a not-yet-implemented proposed parent
     * wait for the next run. Idempotent: converted proposals are skipped.
     *
     * @return array{created: int, waiting: int}
     */
    public function generateChangeRequests(User $actor, OrganizationDesignStudy $study): array
    {
        if ($study->status !== OrganizationDesignStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_approved')]);
        }
        $created = 0;
        $waiting = 0;
        $proposals = UnitDesignProposal::query()->where('study_id', $study->getKey())->whereIn('status', ['accepted', 'proposed'])->orderBy('sequence')->orderBy('created_at')->get();

        foreach ($proposals as $proposal) {
            $parentId = $this->resolvedParentId($proposal);
            if ($proposal->proposal_type !== 'deactivate' && $proposal->parent_proposed_unit_id !== null && $parentId === null) {
                $waiting++;
                continue;
            }
            DB::transaction(function () use ($actor, $study, $proposal, $parentId): void {
                $request = $this->changeRequests->create($actor, [
                    'request_type' => $this->requestType($proposal)->value,
                    'organization_id' => $study->organization_id,
                    'reason' => __('organization-design.change_request_reason', ['reference' => $study->reference_number, 'title' => $study->title_en]).($proposal->design_rationale ? ' — '.$proposal->design_rationale : ''),
                    'priority' => 'normal',
                    'requested_effective_date' => $proposal->effective_from?->toDateString() ?? $study->target_effective_date?->toDateString(),
                    'payload' => $this->payload($proposal, $parentId),
                ]);
                $proposal->forceFill(['organizational_change_request_id' => $request->getKey(), 'status' => 'converted', 'updated_by' => $actor->getKey()])->save();
                $this->audit->record(AuditEventType::UnitProposalConverted, $actor, $proposal, ['organizational_change_request_id' => $request->getKey(), 'request_no' => $request->request_no]);
            });
            $created++;
        }

        return ['created' => $created, 'waiting' => $waiting];
    }

    /**
     * Proposals whose change request has been implemented adopt the
     * resulting unit: responsibilities move from the proposal to the unit.
     *
     * @return int proposals adopted
     */
    public function adoptImplemented(User $actor, OrganizationDesignStudy $study): int
    {
        $adopted = 0;
        $proposals = UnitDesignProposal::query()->where('study_id', $study->getKey())->where('status', 'converted')->whereNotNull('organizational_change_request_id')->with('changeRequest.items')->get();
        foreach ($proposals as $proposal) {
            $item = $proposal->changeRequest?->items->first();
            $unitId = $item?->resulting_entity_id ?? ($proposal->proposal_type === 'create' ? null : $proposal->existing_unit_id);
            if ($item?->resulting_entity_id === null && $proposal->proposal_type === 'create') {
                continue;
            }
            if ($unitId === null || ! OrganizationUnit::query()->whereKey($unitId)->exists()) {
                continue;
            }
            DB::transaction(function () use ($actor, $proposal, $unitId, &$adopted): void {
                $proposal->forceFill(['resulting_unit_id' => $unitId, 'status' => 'implemented', 'updated_by' => $actor->getKey()])->save();
                foreach (UnitResponsibility::query()->where('unit_design_proposal_id', $proposal->getKey())->get() as $row) {
                    $exists = UnitResponsibility::query()->where('organization_unit_id', $unitId)->where('object_type', $row->object_type)->where('object_id', $row->object_id)->where('responsibility_type_id', $row->responsibility_type_id)->exists();
                    if ($exists) {
                        $row->delete();
                    } else {
                        $row->forceFill(['organization_unit_id' => $unitId, 'unit_design_proposal_id' => null])->save();
                    }
                }
                $this->audit->record(AuditEventType::UnitProposalImplemented, $actor, $proposal, ['resulting_unit_id' => $unitId]);
                $adopted++;
            });
        }

        return $adopted;
    }

    // ── Reads ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function tree(OrganizationDesignStudy $study): array
    {
        $proposals = $study->proposals()->with(['existingUnit:id,code,name_en,name_am', 'parentExisting:id,code,name_en,name_am', 'unitType:id,code,name_en,name_am', 'changeRequest:id,request_no,status', 'resultingUnit:id,code,name_en,name_am', 'responsibilities.type:id,code,name_en,name_am'])->get();
        $nodes = [];
        foreach ($proposals as $p) {
            $nodes[$p->id] = [
                ...$p->only(['id', 'proposal_type', 'existing_unit_id', 'parent_proposed_unit_id', 'parent_existing_unit_id', 'proposed_unit_type_id', 'unit_type', 'name_en', 'name_am', 'description_en', 'description_am', 'design_rationale', 'sequence', 'status', 'organizational_change_request_id', 'resulting_unit_id']),
                'effective_from' => $p->effective_from?->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
                'existing_unit' => $p->existingUnit?->only(['id', 'code', 'name_en', 'name_am']),
                'parent_existing' => $p->parentExisting?->only(['id', 'code', 'name_en', 'name_am']),
                'unit_type_record' => $p->unitType?->only(['id', 'code', 'name_en', 'name_am']),
                'change_request' => $p->changeRequest ? ['id' => $p->changeRequest->id, 'request_no' => $p->changeRequest->request_no, 'status' => $p->changeRequest->status instanceof \BackedEnum ? $p->changeRequest->status->value : $p->changeRequest->status] : null,
                'resulting_unit' => $p->resultingUnit?->only(['id', 'code', 'name_en', 'name_am']),
                'responsibilities' => ResponsibilityService::rows($p->responsibilities),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($proposals as $p) {
            if ($p->parent_proposed_unit_id !== null && isset($nodes[$p->parent_proposed_unit_id])) {
                $nodes[$p->parent_proposed_unit_id]['children'][] = &$nodes[$p->id];
            } else {
                $roots[] = &$nodes[$p->id];
            }
        }

        return $roots;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function nextReference(): string
    {
        $prefix = self::PREFIX.'-'.now()->format('Y').'-';
        $last = OrganizationDesignStudy::query()->withTrashed()->where('reference_number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('reference_number')->value('reference_number');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data */
    private function validateProposal(OrganizationDesignStudy $study, array $data, ?UnitDesignProposal $current): void
    {
        $type = $data['proposal_type'] ?? 'create';
        $existing = $data['existing_unit_id'] ?? null;
        if ($type === 'create' && $existing !== null) {
            throw ValidationException::withMessages(['existing_unit_id' => __('organization-design.proposal_create_no_existing')]);
        }
        if ($type !== 'create' && $existing === null) {
            throw ValidationException::withMessages(['existing_unit_id' => __('organization-design.proposal_existing_required')]);
        }
        if ($type === 'move' && empty($data['parent_existing_unit_id']) && empty($data['parent_proposed_unit_id'])) {
            throw ValidationException::withMessages(['parent_existing_unit_id' => __('organization-design.proposal_move_needs_parent')]);
        }
        if (! empty($data['parent_existing_unit_id']) && ! empty($data['parent_proposed_unit_id'])) {
            throw ValidationException::withMessages(['parent_proposed_unit_id' => __('organization-design.proposal_two_parents')]);
        }
        if (! empty($data['parent_proposed_unit_id'])) {
            $parent = UnitDesignProposal::query()->whereKey($data['parent_proposed_unit_id'])->where('study_id', $study->getKey())->first();
            if ($parent === null) {
                throw ValidationException::withMessages(['parent_proposed_unit_id' => __('organization-design.proposal_parent_invalid')]);
            }
            if ($current !== null) {
                for ($node = $parent; $node !== null; $node = $node->parentProposed) {
                    if ($node->getKey() === $current->getKey()) {
                        throw ValidationException::withMessages(['parent_proposed_unit_id' => __('organization-design.proposal_cycle')]);
                    }
                }
            }
        }
    }

    private function resolvedParentId(UnitDesignProposal $proposal): ?string
    {
        if ($proposal->parent_existing_unit_id !== null) {
            return $proposal->parent_existing_unit_id;
        }
        if ($proposal->parent_proposed_unit_id !== null) {
            return $proposal->parentProposed?->resulting_unit_id;
        }

        return null;
    }

    private function requestType(UnitDesignProposal $proposal): OrganizationalChangeRequestType
    {
        return match ($proposal->proposal_type) {
            'update' => OrganizationalChangeRequestType::UpdateOrganizationUnit,
            'move' => OrganizationalChangeRequestType::MoveOrganizationUnit,
            'deactivate' => OrganizationalChangeRequestType::DeactivateOrganizationUnit,
            default => OrganizationalChangeRequestType::AddOrganizationUnit,
        };
    }

    /** @return array<string, mixed> */
    private function payload(UnitDesignProposal $proposal, ?string $parentId): array
    {
        $common = array_filter([
            'name_en' => $proposal->name_en, 'name_am' => $proposal->name_am, 'description_en' => $proposal->description_en, 'description_am' => $proposal->description_am,
            'organization_unit_type_id' => $proposal->proposed_unit_type_id, 'unit_type' => $proposal->unit_type ?? $proposal->unitType?->code,
            'effective_from' => $proposal->effective_from?->toDateString(),
        ], fn ($v) => $v !== null && $v !== '');

        return match ($proposal->proposal_type) {
            'update' => ['entity_id' => $proposal->existing_unit_id, ...$common],
            'move' => ['entity_id' => $proposal->existing_unit_id, 'parent_unit_id' => $parentId, 'effective_from' => $proposal->effective_from?->toDateString()],
            'deactivate' => ['entity_id' => $proposal->existing_unit_id, 'effective_to' => $proposal->effective_to?->toDateString()],
            default => ['parent_unit_id' => $parentId, ...$common],
        };
    }

    private function assertEditable(OrganizationDesignStudy $study): void
    {
        if (! $study->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_draft')]);
        }
    }
}
