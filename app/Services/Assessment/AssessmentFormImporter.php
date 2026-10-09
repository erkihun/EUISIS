<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Http\Requests\Assessment\SaveAssessmentDraftRequest;
use App\Models\AssessmentForm;
use App\Models\AssessmentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates an assessment form from a definition file instead of typing it
 * into the builder (docs/assessment-form-builder.md#importing-a-form).
 *
 * The definition is data: { form: {...}, version: <the builder's draft
 * payload> }. It goes through AssessmentFormService, so it is validated,
 * versioned and audited exactly like a form made in the UI. A form whose
 * code already exists is never changed: once imported, administrators own it.
 */
final class AssessmentFormImporter
{
    public function __construct(private readonly AssessmentFormService $forms) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{status: 'created'|'exists', form: AssessmentForm, published: bool, problems: array<string, string>}
     *
     * @throws ValidationException when the definition is malformed
     */
    public function import(User $actor, array $definition, bool $publish = false): array
    {
        $form = Validator::make((array) ($definition['form'] ?? []), [
            'code' => ['required', 'string', 'max:50', 'alpha_dash'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'assessment_type' => ['required', 'string', Rule::exists('assessment_types', 'code')],
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
        ])->validate();
        $version = Validator::make((array) ($definition['version'] ?? []), (new SaveAssessmentDraftRequest)->rules())->validate();

        $existing = AssessmentForm::query()->where('code', $form['code'])->first();
        if ($existing !== null) {
            return ['status' => 'exists', 'form' => $existing, 'published' => false, 'problems' => []];
        }

        return DB::transaction(function () use ($actor, $form, $version, $publish): array {
            $created = $this->forms->create($actor, [
                ...$form,
                'assessment_type_id' => AssessmentType::query()->where('code', $form['assessment_type'])->value('id'),
                'scoring_method' => $version['scoring_method'],
            ]);
            $draft = $this->forms->saveDraft($actor, $created->draftVersion(), $version);
            $problems = $this->forms->validate($actor, $draft);

            $published = false;
            if ($publish && $problems === []) {
                $this->forms->publish($actor, $draft->fresh());
                $published = true;
            }

            return ['status' => 'created', 'form' => $created->fresh(), 'published' => $published, 'problems' => $problems];
        });
    }
}
