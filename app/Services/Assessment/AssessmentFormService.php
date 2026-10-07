<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\Assessment\FormVersionStatus;
use App\Enums\AuditEventType;
use App\Models\AssessmentForm;
use App\Models\AssessmentFormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of an assessment form (docs/assessment-form-builder.md).
 *
 *   create   → form + draft v1
 *   save     → replaces the draft's content (only a draft is ever edited)
 *   publish  → validated, immutable; the previous published version is superseded
 *   version  → a new draft copied from the latest version
 *   clone    → an independent form whose draft copies another form
 *   archive  → no longer offered; history is kept
 *
 * One draft per form at a time. Every step is audited with what changed.
 */
class AssessmentFormService
{
    /** Header fields of a version an administrator edits. */
    public const VERSION_FIELDS = [
        'name_en', 'name_am', 'description_en', 'description_am', 'instructions_en', 'instructions_am',
        'period_type', 'scoring_method', 'max_total_score', 'overall_contribution_weight', 'result_scale_id',
        'acknowledgement_required', 'review_required', 'effective_from', 'effective_to',
    ];

    public function __construct(
        private readonly AssessmentScoringService $scoring,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): AssessmentForm
    {
        return DB::transaction(function () use ($actor, $data): AssessmentForm {
            $form = AssessmentForm::query()->create([
                'code' => $data['code'],
                'name_en' => $data['name_en'],
                'name_am' => $data['name_am'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'description_am' => $data['description_am'] ?? null,
                'assessment_type_id' => $data['assessment_type_id'],
                'organization_id' => $data['organization_id'] ?? null,
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $form->versions()->create([
                'version_no' => 1,
                'status' => FormVersionStatus::Draft,
                'name_en' => $form->name_en,
                'name_am' => $form->name_am,
                'description_en' => $form->description_en,
                'description_am' => $form->description_am,
                'scoring_method' => $data['scoring_method'] ?? 'percent_of_max',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->record(AuditEventType::AssessmentFormCreated, $actor, $form, null, ['code' => $form->code, 'name_en' => $form->name_en]);

            return $form;
        });
    }

    /**
     * Replace a draft's header, sections, criteria, options, target rules
     * and evaluator scheme with the submitted ones. A draft is not used by
     * any assessment, so its rows are rebuilt; published versions are
     * refused.
     *
     * @param  array<string, mixed>  $data  validated payload
     */
    public function saveDraft(User $actor, AssessmentFormVersion $version, array $data): AssessmentFormVersion
    {
        return DB::transaction(function () use ($actor, $version, $data): AssessmentFormVersion {
            $version = AssessmentFormVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);
            $before = $this->summary($version->load(['sections.criteria.options', 'targetRules', 'evaluatorSchemes']));

            $version->fill([...array_intersect_key($data, array_flip(self::VERSION_FIELDS)), 'updated_by' => $actor->id])->save();

            $version->sections()->delete();
            foreach (array_values($data['sections'] ?? []) as $s => $sectionData) {
                $section = $version->sections()->create([
                    ...array_intersect_key($sectionData, array_flip(['code', 'title_en', 'title_am', 'description_en', 'description_am', 'weight', 'max_score', 'is_required'])),
                    'sort_order' => $s,
                ]);
                foreach (array_values($sectionData['criteria'] ?? []) as $c => $criterionData) {
                    $criterion = $section->criteria()->create([
                        ...array_intersect_key($criterionData, array_flip(['competency_id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'max_score', 'weight', 'is_required', 'comment_mode', 'evidence_mode'])),
                        'form_version_id' => $version->id,
                        'sort_order' => $c,
                    ]);
                    foreach (array_values($criterionData['options'] ?? []) as $o => $optionData) {
                        $criterion->options()->create([
                            ...array_intersect_key($optionData, array_flip(['label_en', 'label_am', 'description_en', 'description_am', 'score'])),
                            'sort_order' => $o,
                        ]);
                    }
                }
            }

            $version->targetRules()->delete();
            foreach (array_values($data['target_rules'] ?? []) as $ruleData) {
                $version->targetRules()->create(array_intersect_key($ruleData, array_flip(['target_type', 'target_id', 'target_value', 'include_descendants', 'effect', 'priority', 'effective_from', 'effective_to'])));
            }

            $version->evaluatorSchemes()->delete();
            foreach (array_values($data['evaluators'] ?? []) as $e => $schemeData) {
                $version->evaluatorSchemes()->create([
                    ...array_intersect_key($schemeData, array_flip(['evaluator_type', 'required_count', 'contribution_weight', 'selection_method', 'aggregation_method', 'is_anonymous', 'requires_review'])),
                    'sort_order' => $e,
                ]);
            }

            $after = $this->summary($version->fresh(['sections.criteria.options', 'targetRules', 'evaluatorSchemes']));
            if ($before !== $after) {
                $this->record(AuditEventType::AssessmentFormDraftSaved, $actor, $version->form, $before, $after);
            }

            return $version;
        });
    }

    /** @return array<string, string> problems; empty when the draft can be published */
    public function validate(User $actor, AssessmentFormVersion $version): array
    {
        $errors = $this->scoring->validateConfiguration($this->loaded($version));
        $this->record(AuditEventType::AssessmentFormValidated, $actor, $version->form, null, ['version_no' => $version->version_no, 'valid' => $errors === [], 'problems' => count($errors)]);

        return $errors;
    }

    public function publish(User $actor, AssessmentFormVersion $version): AssessmentFormVersion
    {
        return DB::transaction(function () use ($actor, $version): AssessmentFormVersion {
            AssessmentForm::query()->whereKey($version->form_id)->lockForUpdate()->first();
            $version = AssessmentFormVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            $errors = $this->scoring->validateConfiguration($this->loaded($version));
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $previous = AssessmentFormVersion::query()
                ->where('form_id', $version->form_id)
                ->where('status', FormVersionStatus::Published->value)
                ->lockForUpdate()
                ->get();
            foreach ($previous as $old) {
                $old->forceFill(['status' => FormVersionStatus::Superseded])->save();
            }

            $version->forceFill([
                'status' => FormVersionStatus::Published,
                'published_by' => $actor->id,
                'published_at' => now(),
            ])->save();
            $version->form->forceFill(['current_version_id' => $version->id, 'updated_by' => $actor->id])->save();

            $this->record(AuditEventType::AssessmentFormPublished, $actor, $version->form, ['current_version' => $previous->first()?->version_no], ['current_version' => $version->version_no]);

            return $version;
        });
    }

    /** A new draft copied from the form's latest version. */
    public function newVersion(User $actor, AssessmentForm $form): AssessmentFormVersion
    {
        return DB::transaction(function () use ($actor, $form): AssessmentFormVersion {
            $form = AssessmentForm::query()->whereKey($form->getKey())->lockForUpdate()->firstOrFail();
            if ($form->draftVersion() !== null) {
                throw ValidationException::withMessages(['version' => __('assessments.errors.draft_exists')]);
            }
            $source = $form->currentVersion ?? $form->versions()->latest('version_no')->firstOrFail();
            $next = (int) $form->versions()->max('version_no') + 1;

            $copy = $this->copy($actor, $source, $form, $next);
            $this->record(AuditEventType::AssessmentFormVersionCreated, $actor, $form, ['from_version' => $source->version_no], ['version_no' => $next]);

            return $copy;
        });
    }

    /** @param array<string, mixed> $data code, names, optional organization */
    public function cloneForm(User $actor, AssessmentForm $source, array $data): AssessmentForm
    {
        return DB::transaction(function () use ($actor, $source, $data): AssessmentForm {
            $from = $source->currentVersion ?? $source->versions()->latest('version_no')->firstOrFail();
            $form = AssessmentForm::query()->create([
                'code' => $data['code'],
                'name_en' => $data['name_en'],
                'name_am' => $data['name_am'] ?? null,
                'description_en' => $source->description_en,
                'description_am' => $source->description_am,
                'assessment_type_id' => $source->assessment_type_id,
                'organization_id' => $data['organization_id'] ?? $source->organization_id,
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $copy = $this->copy($actor, $from, $form, 1);
            $copy->forceFill(['name_en' => $form->name_en, 'name_am' => $form->name_am])->save();

            $this->record(AuditEventType::AssessmentFormCloned, $actor, $form, ['source' => $source->code, 'source_version' => $from->version_no], ['code' => $form->code]);

            return $form;
        });
    }

    public function archive(User $actor, AssessmentForm $form): AssessmentForm
    {
        DB::transaction(function () use ($actor, $form): void {
            $form->forceFill(['status' => 'archived', 'updated_by' => $actor->id])->save();
            $form->versions()->where('status', FormVersionStatus::Published->value)->update(['status' => FormVersionStatus::Archived->value]);
            $this->record(AuditEventType::AssessmentFormArchived, $actor, $form, ['status' => 'active'], ['status' => 'archived']);
        });

        return $form;
    }

    public function discardDraft(User $actor, AssessmentFormVersion $version): void
    {
        $this->assertDraft($version);
        if ($version->form->versions()->count() === 1) {
            throw ValidationException::withMessages(['version' => __('assessments.errors.only_version')]);
        }
        $this->record(AuditEventType::AssessmentFormDraftDiscarded, $actor, $version->form, ['version_no' => $version->version_no], null);
        $version->delete();
    }

    /** Deep copy of a version's content into a new draft. */
    private function copy(User $actor, AssessmentFormVersion $source, AssessmentForm $form, int $versionNo): AssessmentFormVersion
    {
        $source = $this->loaded($source);
        $copy = $form->versions()->create([
            ...$source->only(self::VERSION_FIELDS),
            'scoring_method' => $source->scoring_method?->value,
            'period_type' => $source->period_type?->value,
            'version_no' => $versionNo,
            'status' => FormVersionStatus::Draft,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($source->sections as $section) {
            $newSection = $copy->sections()->create($section->only(['code', 'title_en', 'title_am', 'description_en', 'description_am', 'weight', 'max_score', 'sort_order', 'is_required']));
            foreach ($section->criteria as $criterion) {
                $newCriterion = $newSection->criteria()->create([
                    ...$criterion->only(['competency_id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'max_score', 'weight', 'is_required', 'sort_order']),
                    'comment_mode' => $criterion->comment_mode?->value,
                    'evidence_mode' => $criterion->evidence_mode?->value,
                    'form_version_id' => $copy->id,
                ]);
                foreach ($criterion->options as $option) {
                    $newCriterion->options()->create($option->only(['label_en', 'label_am', 'description_en', 'description_am', 'score', 'sort_order']));
                }
            }
        }
        foreach ($source->targetRules as $rule) {
            $copy->targetRules()->create([...$rule->only(['target_id', 'target_value', 'include_descendants', 'effect', 'priority', 'effective_from', 'effective_to']), 'target_type' => $rule->target_type->value]);
        }
        foreach ($source->evaluatorSchemes as $scheme) {
            $copy->evaluatorSchemes()->create([
                ...$scheme->only(['required_count', 'contribution_weight', 'aggregation_method', 'is_anonymous', 'requires_review', 'sort_order']),
                'evaluator_type' => $scheme->evaluator_type->value,
                'selection_method' => $scheme->selection_method->value,
            ]);
        }

        return $copy;
    }

    private function loaded(AssessmentFormVersion $version): AssessmentFormVersion
    {
        return $version->load(['sections.criteria.options', 'targetRules', 'evaluatorSchemes']);
    }

    private function assertDraft(AssessmentFormVersion $version): void
    {
        if ($version->status !== FormVersionStatus::Draft) {
            throw ValidationException::withMessages(['version' => __('assessments.errors.published_immutable')]);
        }
    }

    /**
     * What an audit entry records of a version: structure, titles and
     * scores, not long descriptions.
     *
     * @return array<string, mixed>
     */
    private function summary(AssessmentFormVersion $version): array
    {
        return [
            'version_no' => $version->version_no,
            'scoring_method' => $version->scoring_method?->value,
            'max_total_score' => $version->max_total_score,
            'overall_contribution_weight' => $version->overall_contribution_weight,
            'sections' => $version->sections->map(fn ($section): array => [
                'title' => $section->title_en,
                'weight' => $section->weight,
                'max' => $section->max_score,
                'criteria' => $section->criteria->map(fn ($criterion): array => [
                    'title' => $criterion->title_en,
                    'max' => $criterion->max_score,
                    'scores' => $criterion->options->pluck('score')->all(),
                ])->all(),
            ])->all(),
            'targets' => $version->targetRules->map(fn ($rule): string => $rule->effect.':'.$rule->target_type->value.':'.($rule->target_id ?? $rule->target_value ?? '*'))->all(),
            'evaluators' => $version->evaluatorSchemes->map(fn ($scheme): string => $scheme->evaluator_type->value.':'.$scheme->required_count.':'.($scheme->contribution_weight ?? '-'))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(AuditEventType $event, User $actor, AssessmentForm $form, ?array $before, ?array $after): void
    {
        $this->audit->execute($event, $actor, $form, $form->organization_id, $before, $after, request: request());
    }
}
