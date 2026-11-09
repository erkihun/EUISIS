<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use Illuminate\Foundation\Http\FormRequest;

/** The requirement sets of an activity or task; only the sets present are replaced. */
class RequirementSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->subject()->process) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'occupations' => ['sometimes', 'array', 'max:50'],
            'occupations.*.occupation_id' => ['required', 'uuid', 'exists:occupations,id'],
            'occupations.*.profession_id' => ['nullable', 'uuid', 'exists:professions,id'],
            'occupations.*.specialization_id' => ['nullable', 'uuid', 'exists:specializations,id'],
            'occupations.*.is_primary' => ['nullable', 'boolean'],
            'competencies' => ['sometimes', 'array', 'max:100'],
            'competencies.*.competency_id' => ['required', 'uuid', 'exists:competencies,id'],
            'competencies.*.minimum_level_id' => ['required', 'uuid', 'exists:competency_levels,id'],
            'competencies.*.is_mandatory' => ['nullable', 'boolean'],
            'qualifications' => ['sometimes', 'array', 'max:50'],
            'qualifications.*.education_level_id' => ['required', 'uuid', 'exists:education_levels,id'],
            'qualifications.*.field_of_study' => ['nullable', 'string', 'max:255'],
            'qualifications.*.certification' => ['nullable', 'string', 'max:255'],
            'qualifications.*.license' => ['nullable', 'string', 'max:255'],
            'qualifications.*.experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'qualifications.*.is_mandatory' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'occupations.*.occupation_id' => __('organization-design.fields.occupation'),
            'occupations.*.profession_id' => __('organization-design.fields.profession'),
            'occupations.*.specialization_id' => __('organization-design.fields.specialization'),
            'competencies.*.competency_id' => __('organization-design.fields.competency'),
            'competencies.*.minimum_level_id' => __('organization-design.fields.minimum_level'),
            'qualifications.*.education_level_id' => __('organization-design.fields.education_level'),
            'qualifications.*.experience_years' => __('organization-design.fields.experience_years'),
        ];
    }

    public function subject(): ProcessActivity|ProcessTask
    {
        $id = (string) $this->route('subject');
        $model = match ((string) $this->route('subjectType')) {
            'activity' => ProcessActivity::query()->find($id),
            'task' => ProcessTask::query()->find($id),
            default => null,
        };

        return $model ?? abort(404);
    }
}
