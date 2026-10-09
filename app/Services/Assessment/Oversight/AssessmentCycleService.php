<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleOrganization;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResultBandPolicy;
use App\Models\AssessmentUnassessedReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycles and their participating institutions. Participation is explicit:
 * an organization takes part only once it is added here.
 */
class AssessmentCycleService
{
    /** Fields that change the denominator; frozen once eligibility is finalized. */
    private const ELIGIBILITY_FIELDS = ['assessment_type_id', 'period_start', 'period_end', 'reference_date', 'eligible_employee_statuses', 'population_rule', 'min_service_days', 'exclusion_reduces_denominator'];

    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function save(User $actor, ?AssessmentCycle $cycle, array $data): AssessmentCycle
    {
        if (! empty($data['result_band_policy_id'])) {
            $policy = AssessmentResultBandPolicy::query()->findOrFail($data['result_band_policy_id']);
            if ($policy->status !== 'active' && $policy->id !== $cycle?->result_band_policy_id) {
                throw ValidationException::withMessages(['result_band_policy_id' => 'Pin an active result-band policy.']);
            }
        }

        return DB::transaction(function () use ($actor, $cycle, $data): AssessmentCycle {
            if ($cycle === null) {
                $cycle = AssessmentCycle::query()->create([...$data, 'status' => 'draft', 'eligibility_status' => 'open', 'created_by' => $actor->id]);
                $this->linkRecords($cycle);
                $this->audit->execute(AuditEventType::AssessmentCycleChanged, $actor, $cycle, newValues: ['action' => 'created'] + $data, request: request());

                return $cycle;
            }

            if ($cycle->isEligibilityFinalized()) {
                $changed = collect(self::ELIGIBILITY_FIELDS)->filter(fn (string $field): bool => array_key_exists($field, $data) && $this->differs($cycle, $field, $data[$field]));
                if ($changed->isNotEmpty()) {
                    throw ValidationException::withMessages(['cycle' => 'Eligibility is finalized; these settings can no longer change: '.$changed->implode(', ').'.']);
                }
            }
            if ($cycle->result_band_policy_id !== null && ($data['result_band_policy_id'] ?? null) !== $cycle->result_band_policy_id
                && AssessmentInstitutionSubmission::query()->where('assessment_cycle_id', $cycle->id)->where('status', 'finalized')->exists()) {
                throw ValidationException::withMessages(['result_band_policy_id' => 'Finalized submissions use this band policy; it can no longer change.']);
            }
            $old = $cycle->only(array_keys($data));
            $cycle->update($data);
            if ($cycle->wasChanged(['assessment_type_id', 'period_start', 'period_end'])) {
                $this->linkRecords($cycle);
            }
            $this->audit->execute(AuditEventType::AssessmentCycleChanged, $actor, $cycle, oldValues: $old, newValues: ['action' => 'updated'] + $cycle->getChanges(), request: request());
            AssessmentCoverageService::bump($cycle->id);

            return $cycle;
        });
    }

    public function setStatus(User $actor, AssessmentCycle $cycle, string $status): void
    {
        $allowed = ['draft' => ['active'], 'active' => ['closed'], 'closed' => []];
        if (! in_array($status, $allowed[$cycle->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$cycle->status} cycle cannot become {$status}."]);
        }
        if ($status === 'active' && $cycle->organizations()->where('status', 'included')->doesntExist()) {
            throw ValidationException::withMessages(['status' => 'Add the participating institutions before activating.']);
        }
        $old = $cycle->status;
        $cycle->update(['status' => $status]);
        $this->audit->execute(AuditEventType::AssessmentCycleChanged, $actor, $cycle, oldValues: ['status' => $old], newValues: ['action' => 'status', 'status' => $status], request: request());
    }

    /** @param array<int, string> $organizationIds */
    public function addOrganizations(User $actor, AssessmentCycle $cycle, array $organizationIds): int
    {
        $this->assertOpen($cycle);
        $added = 0;
        foreach (array_unique($organizationIds) as $organizationId) {
            $row = AssessmentCycleOrganization::query()->firstOrNew(['assessment_cycle_id' => $cycle->id, 'organization_id' => $organizationId]);
            if ($row->exists && $row->status === 'included') {
                continue;
            }
            $row->fill(['status' => 'included', 'included_at' => now(), 'excluded_at' => null, 'exclusion_reason' => null, 'added_by' => $actor->id])->save();
            $added++;
            $this->audit->execute(AuditEventType::AssessmentCycleOrganizationChanged, $actor, $row, $organizationId, newValues: ['action' => 'included'], request: request());
        }
        AssessmentCoverageService::bump($cycle->id);

        return $added;
    }

    /** Withdraw an institution from the cycle, with a reason; kept as a row for history. */
    public function removeOrganization(User $actor, AssessmentCycle $cycle, string $organizationId, string $reason): void
    {
        $this->assertOpen($cycle);
        $row = AssessmentCycleOrganization::query()->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->firstOrFail();
        $row->update(['status' => 'excluded', 'excluded_at' => now(), 'exclusion_reason' => $reason]);
        $this->audit->execute(AuditEventType::AssessmentCycleOrganizationChanged, $actor, $row, $organizationId, newValues: ['action' => 'excluded'], reason: $reason, request: request());
        AssessmentCoverageService::bump($cycle->id);
    }

    public function saveReason(User $actor, ?AssessmentUnassessedReason $reason, array $data): AssessmentUnassessedReason
    {
        if ($reason?->is_system) {
            // The detector depends on system codes; only wording and order may change.
            $data = array_intersect_key($data, array_flip(['name_en', 'name_am', 'sort_order']));
        }
        $old = $reason?->only(array_keys($data));
        $reason ??= new AssessmentUnassessedReason;
        $reason->fill($data)->save();
        $this->audit->execute(AuditEventType::AssessmentUnassessedReasonChanged, $actor, $reason, oldValues: $old, newValues: $data, request: request());

        return $reason;
    }

    /** Attach existing records with the cycle's type and exact period. */
    private function linkRecords(AssessmentCycle $cycle): void
    {
        AssessmentRecord::query()->where('assessment_cycle_id', $cycle->id)->update(['assessment_cycle_id' => null]);
        AssessmentRecord::query()->whereNull('assessment_cycle_id')->where('assessment_type_id', $cycle->assessment_type_id)
            ->whereDate('period_start', $cycle->period_start->toDateString())->whereDate('period_end', $cycle->period_end->toDateString())
            ->update(['assessment_cycle_id' => $cycle->id]);
    }

    private function assertOpen(AssessmentCycle $cycle): void
    {
        if ($cycle->isEligibilityFinalized()) {
            throw ValidationException::withMessages(['organizations' => 'Eligibility is finalized; participation can no longer change.']);
        }
    }

    private function differs(AssessmentCycle $cycle, string $field, mixed $value): bool
    {
        $current = $cycle->getAttribute($field);
        if ($current instanceof \DateTimeInterface) {
            return $current->format('Y-m-d') !== (string) $value;
        }

        if (is_array($current) || is_array($value)) {
            $sorted = fn ($list): array => collect((array) $list)->map(fn ($v): string => (string) $v)->sort()->values()->all();

            return $sorted($current) !== $sorted($value);
        }

        return ($current === null ? null : (string) $current) !== ($value === null || $value === '' ? null : (string) $value);
    }
}
