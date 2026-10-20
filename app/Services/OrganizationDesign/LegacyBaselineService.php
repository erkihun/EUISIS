<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\LegacyBaseline;
use App\Models\LegacyBaselineStep;
use App\Models\User;
use App\Services\OrganizationDesign\Concerns\BuildsDesignFindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The legacy baseline workflow (ADR-LB-002/003):
 *
 *   draft ──submit──▶ under review ──approve──▶ approved ──publish──▶ published ──▶ superseded
 *     ▲                    │ return
 *     └────────────────────┘
 *
 *  - a draft may stay incomplete; validation is advisory until submission
 *    and a gate at approval and publication;
 *  - the person who prepared a baseline never approves or publishes it;
 *  - publication is one transaction: snapshot frozen, earlier published
 *    baseline of the organization superseded, status history written;
 *  - a published baseline is never edited: a new revision is drafted from it.
 */
class LegacyBaselineService
{
    use BuildsDesignFindings;

    public const PREFIX = 'LOB';

    public const FIELDS = ['title_en', 'title_am', 'description', 'origin', 'effective_date', 'hierarchy_version_id', 'legal_source_id'];

    public function __construct(
        private readonly OrganizationDesignAudit $audit,
        private readonly LegacyBaselineStepRegistry $steps,
        private readonly LegacyBaselineSnapshotService $snapshots,
        private readonly OrganizationDesignValidationService $validation,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, string $organizationId, array $data): LegacyBaseline
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): LegacyBaseline {
            $baseline = new LegacyBaseline([
                ...$this->only($data),
                'organization_id' => $organizationId,
                'lineage_key' => (string) Str::uuid(),
                'version_no' => 1,
                'reference_number' => $this->nextReference(),
                'origin' => $data['origin'] ?? 'manual',
                'status' => OrganizationDesignStatus::Draft,
                'prepared_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $baseline->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselineCreated, $actor, $baseline, $baseline->only(['reference_number', ...self::FIELDS]));
            $this->audit->transition($actor, $baseline, null, OrganizationDesignStatus::Draft->value);

            return $baseline;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, LegacyBaseline $baseline, array $data): LegacyBaseline
    {
        $this->assertDraft($baseline);

        return DB::transaction(function () use ($actor, $baseline, $data): LegacyBaseline {
            $old = $baseline->only(self::FIELDS);
            $baseline->fill([...$this->only($data), 'updated_by' => $actor->getKey()])->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselineUpdated, $actor, $baseline, $baseline->only(self::FIELDS), $old);

            return $baseline;
        });
    }

    /**
     * Re-detects the organization's records for every capture step. A step a
     * person set by hand keeps that status; the others follow the count.
     */
    public function refreshSteps(LegacyBaseline $baseline): void
    {
        $detected = $this->steps->detect($baseline);
        $existing = $baseline->steps()->get()->keyBy('step_key');

        foreach (LegacyBaselineStepRegistry::keys() as $key) {
            $count = $detected[$key] ?? 0;
            $step = $existing->get($key);
            $isWorkflow = in_array($key, LegacyBaselineStepRegistry::WORKFLOW_STEPS, true);
            $auto = $isWorkflow ? $this->workflowStepStatus($baseline, $key) : ($count > 0 ? 'complete' : 'incomplete');

            if ($step === null) {
                LegacyBaselineStep::query()->create(['baseline_id' => $baseline->getKey(), 'step_key' => $key, 'status' => $auto, 'status_source' => 'auto', 'record_count' => $count, 'updated_at' => now()]);
            } else {
                $step->forceFill(['record_count' => $count, 'status' => $step->status_source === 'manual' && ! $isWorkflow ? $step->status : $auto, 'updated_at' => now()])->save();
            }
        }
    }

    /** A person records what a count cannot say: "no lower offices", "needs review", or an error found by hand. */
    public function saveStep(User $actor, LegacyBaseline $baseline, string $stepKey, string $status, ?string $notes): LegacyBaselineStep
    {
        $this->assertDraft($baseline);
        if (! in_array($stepKey, LegacyBaselineStepRegistry::keys(), true) || in_array($stepKey, LegacyBaselineStepRegistry::WORKFLOW_STEPS, true)) {
            throw ValidationException::withMessages(['step' => __('organization-design.baseline_step_unknown')]);
        }
        if (! in_array($status, LegacyBaselineStep::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_step_status_invalid')]);
        }

        return DB::transaction(function () use ($actor, $baseline, $stepKey, $status, $notes): LegacyBaselineStep {
            $step = LegacyBaselineStep::query()->firstOrCreate(['baseline_id' => $baseline->getKey(), 'step_key' => $stepKey], ['status' => 'incomplete', 'status_source' => 'auto', 'record_count' => 0, 'updated_at' => now()]);
            $step->forceFill(['status' => $status, 'status_source' => 'manual', 'notes' => $notes, 'updated_by' => $actor->getKey(), 'updated_at' => now()])->save();
            $this->audit->record(AuditEventType::LegacyBaselineStepSaved, $actor, $baseline, ['step' => $stepKey, 'status' => $status]);

            return $step;
        });
    }

    /**
     * The full checklist: the structure validation of the organization plus
     * the baseline's own rules. Stored on the baseline so the page, the
     * gate and the report read one result.
     *
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    public function validate(?User $actor, LegacyBaseline $baseline): array
    {
        $this->refreshSteps($baseline);
        $subject = ['type' => 'baseline', 'id' => $baseline->id, 'code' => $baseline->reference_number, 'name' => $baseline->title_en];
        $findings = $this->validation->validateStructure($baseline->organization_id)['findings'];

        if ($baseline->legal_source_id === null && ! $baseline->citations()->exists()) {
            $findings[] = $this->finding('error', 'baseline_without_legal_source', $subject);
        }
        if ($baseline->effective_date === null) {
            $findings[] = $this->finding('error', 'baseline_without_effective_date', $subject);
        }
        if (! InstitutionMandate::query()->where('organization_id', $baseline->organization_id)->where('status', OrganizationDesignStatus::Published)->exists()) {
            $findings[] = $this->finding('error', 'baseline_without_published_mandate', $subject);
        }
        if ($baseline->hierarchy_version_id === null) {
            $findings[] = $this->finding('warning', 'baseline_without_hierarchy_version', $subject);
        }
        if ($baseline->mapping_required_count > 0) {
            $findings[] = $this->finding('warning', 'baseline_mapping_required', $subject, ['count' => $baseline->mapping_required_count]);
        }
        foreach ($baseline->steps()->get() as $step) {
            if (in_array($step->step_key, LegacyBaselineStepRegistry::WORKFLOW_STEPS, true)) {
                continue;
            }
            $stepSubject = ['type' => 'baseline_step', 'id' => $step->id, 'code' => $baseline->reference_number, 'name' => __('organization-design.baseline_steps.'.$step->step_key)];
            match ($step->status) {
                'error' => $findings[] = $this->finding('error', 'baseline_step_error', $stepSubject, ['notes' => (string) $step->notes]),
                'needs_review' => $findings[] = $this->finding('warning', 'baseline_step_needs_review', $stepSubject, ['notes' => (string) $step->notes]),
                'incomplete' => $findings[] = $this->finding('warning', 'baseline_step_incomplete', $stepSubject),
                default => null,
            };
        }

        $report = $this->report($findings);
        $baseline->forceFill([
            'validation_status' => ! $report['ok'] ? 'failed' : ($report['summary']['warnings'] > 0 ? 'warnings' : 'passed'),
            'validation_summary' => $report['summary'],
            'validated_at' => now(),
        ])->save();

        return $report;
    }

    public function submit(User $actor, LegacyBaseline $baseline): LegacyBaseline
    {
        $this->assertDraft($baseline);
        $report = $this->validate($actor, $baseline);
        if (! $report['ok']) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_submit_blocked', ['errors' => $report['summary']['errors']])]);
        }

        return DB::transaction(function () use ($actor, $baseline): LegacyBaseline {
            $baseline->forceFill(['status' => OrganizationDesignStatus::UnderReview, 'submitted_at' => now(), 'review_comment' => null, 'updated_by' => $actor->getKey()])->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselineSubmitted, $actor, $baseline, ['reference_number' => $baseline->reference_number]);
            $this->audit->transition($actor, $baseline, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value);

            return $baseline;
        });
    }

    public function returnToDraft(User $actor, LegacyBaseline $baseline, ?string $reason): LegacyBaseline
    {
        if ($baseline->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_not_under_review')]);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('organization-design.baseline_return_reason_required')]);
        }

        return DB::transaction(function () use ($actor, $baseline, $reason): LegacyBaseline {
            $baseline->forceFill(['status' => OrganizationDesignStatus::Draft, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_comment' => $reason, 'updated_by' => $actor->getKey()])->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselineReturned, $actor, $baseline, ['reason' => $reason]);
            $this->audit->transition($actor, $baseline, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value, $reason);

            return $baseline;
        });
    }

    public function approve(User $actor, LegacyBaseline $baseline, ?string $approvalReference): LegacyBaseline
    {
        if ($baseline->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_not_under_review')]);
        }
        $this->assertNotPreparer($actor, $baseline);
        $report = $this->validate($actor, $baseline);
        if (! $report['ok']) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_approve_blocked', ['errors' => $report['summary']['errors']])]);
        }

        return DB::transaction(function () use ($actor, $baseline, $approvalReference): LegacyBaseline {
            $baseline->forceFill(['status' => OrganizationDesignStatus::Approved, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'approval_reference' => $approvalReference, 'updated_by' => $actor->getKey()])->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselineApproved, $actor, $baseline, ['approval_reference' => $approvalReference]);
            $this->audit->transition($actor, $baseline, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value);

            return $baseline;
        });
    }

    /** One transaction: snapshot, supersession of the organization's current published baseline, publication. */
    public function publish(User $actor, LegacyBaseline $baseline): LegacyBaseline
    {
        if ($baseline->status !== OrganizationDesignStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_not_approved')]);
        }
        $this->assertNotPreparer($actor, $baseline);

        return DB::transaction(function () use ($actor, $baseline): LegacyBaseline {
            $current = LegacyBaseline::query()->where('organization_id', $baseline->organization_id)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->get();
            foreach ($current as $previous) {
                $previous->forceFill(['status' => OrganizationDesignStatus::Superseded, 'superseded_by_id' => $baseline->getKey(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->record(AuditEventType::LegacyBaselineSuperseded, $actor, $previous, ['superseded_by' => $baseline->getKey()]);
                $this->audit->transition($actor, $previous, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }

            $snapshot = $this->snapshots->freeze($baseline);
            $baseline->forceFill(['status' => OrganizationDesignStatus::Published, 'published_by' => $actor->getKey(), 'published_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->refreshSteps($baseline);
            $this->audit->record(AuditEventType::LegacyBaselinePublished, $actor, $baseline, ['snapshot_id' => $snapshot->getKey(), 'sha256' => $snapshot->sha256, 'counts' => $snapshot->counts]);
            $this->audit->transition($actor, $baseline, OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::Published->value);

            return $baseline;
        });
    }

    /** A new draft revision of a published baseline; the published one stays as it is. */
    public function newRevision(User $actor, LegacyBaseline $published, array $changes = []): LegacyBaseline
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_revision_from_published_only')]);
        }

        return DB::transaction(function () use ($actor, $published, $changes): LegacyBaseline {
            LegacyBaseline::query()->whereKey($published->getKey())->lockForUpdate()->first();
            if (LegacyBaseline::query()->where('lineage_key', $published->lineage_key)->whereIn('status', [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview, OrganizationDesignStatus::Approved])->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.baseline_revision_exists')]);
            }
            $next = (int) LegacyBaseline::query()->where('lineage_key', $published->lineage_key)->max('version_no') + 1;
            $draft = new LegacyBaseline([
                ...$published->only(self::FIELDS),
                ...$this->only($changes),
                'organization_id' => $published->organization_id,
                'lineage_key' => $published->lineage_key,
                'version_no' => $next,
                'reference_number' => $this->nextReference(),
                'status' => OrganizationDesignStatus::Draft,
                'prepared_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $draft->save();
            $this->refreshSteps($draft);
            $this->audit->record(AuditEventType::LegacyBaselineCreated, $actor, $draft, ['version_no' => $next, 'revises' => $published->getKey()]);
            $this->audit->transition($actor, $draft, null, OrganizationDesignStatus::Draft->value);

            return $draft;
        });
    }

    public function cancel(User $actor, LegacyBaseline $baseline, ?string $reason): LegacyBaseline
    {
        if (! in_array($baseline->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_cancel_blocked')]);
        }

        return DB::transaction(function () use ($actor, $baseline, $reason): LegacyBaseline {
            $from = $baseline->status->value;
            $baseline->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::LegacyBaselineCancelled, $actor, $baseline, [], null, $reason);
            $this->audit->transition($actor, $baseline, $from, OrganizationDesignStatus::Cancelled->value, $reason);

            return $baseline;
        });
    }

    private function workflowStepStatus(LegacyBaseline $baseline, string $key): string
    {
        $status = $baseline->status;

        return match ($key) {
            'validation' => match ($baseline->validation_status) {
                'passed' => 'complete', 'warnings' => 'needs_review', 'failed' => 'error', default => 'incomplete'
            },
            'review' => in_array($status, [OrganizationDesignStatus::Approved, OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true) ? 'complete' : ($status === OrganizationDesignStatus::UnderReview ? 'needs_review' : 'incomplete'),
            'approval' => in_array($status, [OrganizationDesignStatus::Approved, OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true) ? 'complete' : 'incomplete',
            'publish' => in_array($status, [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true) ? 'complete' : 'incomplete',
            default => 'incomplete',
        };
    }

    private function assertDraft(LegacyBaseline $baseline): void
    {
        if ($baseline->status !== OrganizationDesignStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_not_draft')]);
        }
    }

    private function assertNotPreparer(User $actor, LegacyBaseline $baseline): void
    {
        if ($baseline->prepared_by !== null && (int) $baseline->prepared_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.baseline_same_person')]);
        }
    }

    private function nextReference(): string
    {
        $prefix = self::PREFIX.'-'.now()->format('Y').'-';
        $last = LegacyBaseline::query()->withTrashed()->where('reference_number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('reference_number')->value('reference_number');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }
}
