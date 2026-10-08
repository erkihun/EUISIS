<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Models\AssessmentRecord;
use App\Models\AssessmentResultVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The only writer of immutable, authoritative competency-result versions. */
final class AssessmentResultVersionService
{
    public function ensureOriginal(AssessmentRecord $record, ?User $actor): AssessmentResultVersion
    {
        return DB::transaction(function () use ($record, $actor): AssessmentResultVersion {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            $current = AssessmentResultVersion::query()->where('assessment_record_id', $record->id)->where('is_current', true)->lockForUpdate()->first();
            if ($current) return $current;
            return AssessmentResultVersion::query()->create($this->values($record, 1, 'original', null, null, $actor));
        });
    }

    public function revise(AssessmentRecord $record, array $scores, string $changeType, string $reason, User $actor, ?string $reference = null): AssessmentResultVersion
    {
        if (! in_array($changeType, ['appeal_decision', 'moderation', 'technical_correction'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['result' => 'A permitted change type and reason are required.']);
        }
        return DB::transaction(function () use ($record, $scores, $changeType, $reason, $actor, $reference): AssessmentResultVersion {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            $old = AssessmentResultVersion::query()->where('assessment_record_id', $record->id)->where('is_current', true)->lockForUpdate()->firstOrFail();
            $old->update(['is_current' => false, 'current_key' => null]);
            return AssessmentResultVersion::query()->create($this->values($record, $old->version_no + 1, $changeType, $old->id, $reason, $actor, $reference, $scores));
        });
    }

    private function values(AssessmentRecord $record, int $version, string $type, ?string $supersedes, ?string $reason, ?User $actor, ?string $reference = null, array $scores = []): array
    {
        return ['assessment_record_id' => $record->id, 'version_no' => $version, 'raw_score' => $scores['raw_score'] ?? $record->percentage,
            'normalized_score' => $scores['normalized_score'] ?? $record->percentage, 'contribution_score' => $scores['contribution_score'] ?? $record->contribution,
            'result_band_policy_id' => $scores['result_band_policy_id'] ?? $record->band_policy_id, 'result_band_code' => $scores['result_band_code'] ?? $record->band_code,
            'result_band_label_en' => $scores['result_band_label_en'] ?? $record->band_label_en, 'result_band_label_am' => $scores['result_band_label_am'] ?? $record->band_label_am,
            'change_type' => $type, 'supersedes_version_id' => $supersedes, 'effective_at' => now(), 'created_by' => $actor?->id,
            'decision_reference' => $reference, 'reason' => $reason, 'is_current' => true, 'current_key' => $record->id];
    }
}
