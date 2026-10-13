<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\DesignStudyType;
use App\Models\OrganizationDesignStudy;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A design study: created for an organization in scope, updated while a draft. */
class StudyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $study = $this->route('study');
        if ($study instanceof OrganizationDesignStudy) {
            return $user?->can('update', $study) ?? false;
        }
        if ($user === null || ! $user->can('create', OrganizationDesignStudy::class)) {
            return false;
        }

        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $study = $this->route('study');
        $partial = $study instanceof OrganizationDesignStudy;
        $required = $partial ? 'sometimes' : 'required';
        $organizationId = $partial ? $study->organization_id : $this->string('organization_id')->toString();

        return [
            'organization_id' => [$partial ? 'prohibited' : 'required', 'uuid', 'exists:organizations,id'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'study_type' => [$required, Rule::in(DesignStudyType::values())],
            'reason' => ['nullable', 'string', 'max:5000'],
            'scope_notes' => ['nullable', 'string', 'max:5000'],
            'started_at' => ['nullable', 'date_format:Y-m-d'],
            'target_effective_date' => ['nullable', 'date_format:Y-m-d'],
            'hierarchy_version_id' => ['nullable', 'uuid', 'exists:hierarchy_versions,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title_en' => __('organization-design.fields.title_en'), 'study_type' => __('organization-design.fields.study_type')];
    }
}
