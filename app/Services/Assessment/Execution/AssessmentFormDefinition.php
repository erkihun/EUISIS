<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Enums\Assessment\FormVersionStatus;
use App\Models\AssessmentFormVersion;
use Illuminate\Support\Facades\Cache;

/**
 * The renderable definition of a form version: sections → criteria →
 * rating options, in configured order. A published (or superseded) version
 * is immutable, so its definition is cached forever by version id; drafts
 * are never cached. Employee responses are never part of this cache.
 */
class AssessmentFormDefinition
{
    /** Response types the renderer supports today; the builder configures rating options only. */
    public const RESPONSE_TYPES = ['rating_option'];

    /** @return array<string, mixed> */
    public function for(AssessmentFormVersion $version): array
    {
        if ($version->status === FormVersionStatus::Draft) {
            return $this->build($version);
        }

        return Cache::rememberForever(self::key($version->id), fn (): array => $this->build($version));
    }

    public static function key(string $versionId): string
    {
        return 'assessment-form-definition:v1:'.$versionId;
    }

    /** @return array<string, mixed> */
    private function build(AssessmentFormVersion $version): array
    {
        $version->loadMissing(['sections.criteria.options', 'evaluatorSchemes', 'form:id,code,name_en,name_am']);

        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'status' => $version->status->value,
            'form' => $version->form?->only(['id', 'code', 'name_en', 'name_am']),
            'name_en' => $version->name_en, 'name_am' => $version->name_am,
            'instructions_en' => $version->instructions_en, 'instructions_am' => $version->instructions_am,
            'scoring_method' => $version->scoring_method?->value,
            'show_option_scores' => (bool) ($version->show_option_scores ?? true),
            'acknowledgement_required' => (bool) $version->acknowledgement_required,
            'review_required' => (bool) $version->review_required,
            'sections' => $version->sections->values()->map(fn ($section): array => [
                'id' => $section->id, 'code' => $section->code,
                'title_en' => $section->title_en, 'title_am' => $section->title_am,
                'description_en' => $section->description_en, 'description_am' => $section->description_am,
                'max_score' => $section->max_score === null ? null : (string) $section->max_score,
                'criteria' => $section->criteria->values()->map(fn ($criterion): array => [
                    'id' => $criterion->id, 'code' => $criterion->code,
                    'title_en' => $criterion->title_en, 'title_am' => $criterion->title_am,
                    'description_en' => $criterion->description_en, 'description_am' => $criterion->description_am,
                    'max_score' => $criterion->max_score === null ? null : (string) $criterion->max_score,
                    'is_required' => (bool) $criterion->is_required,
                    'comment_mode' => $criterion->comment_mode?->value ?? 'disabled',
                    'evidence_mode' => $criterion->evidence_mode?->value ?? 'disabled',
                    'response_type' => 'rating_option',
                    'options' => $criterion->options->values()->map(fn ($option): array => [
                        'id' => $option->id,
                        'label_en' => $option->label_en, 'label_am' => $option->label_am,
                        'description_en' => $option->description_en, 'description_am' => $option->description_am,
                        'score' => (string) $option->score,
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    }
}
