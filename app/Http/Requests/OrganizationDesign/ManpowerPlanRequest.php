<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\ManpowerPlan;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A manpower plan: created for an organization in scope, edited while a draft. */
class ManpowerPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $plan = $this->route('plan');
        if ($plan instanceof ManpowerPlan) {
            return $user?->can('update', $plan) ?? false;
        }
        if ($user === null || ! $user->can('create', ManpowerPlan::class)) {
            return false;
        }

        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $partial = $plan instanceof ManpowerPlan;
        $required = $partial ? 'sometimes' : 'required';
        $organizationId = $partial ? $plan->organization_id : $this->string('organization_id')->toString();

        return [
            'organization_id' => [$partial ? 'prohibited' : 'required', 'uuid', 'exists:organizations,id'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'methodology_id' => [$required, 'uuid', Rule::exists('workforce_planning_methodologies', 'id')->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])],
            'planning_period_start' => [$required, 'date_format:Y-m-d'],
            'planning_period_end' => [$required, 'date_format:Y-m-d', 'after_or_equal:planning_period_start'],
            'hierarchy_version_id' => ['nullable', 'uuid', 'exists:hierarchy_versions,id'],
            'study_id' => ['nullable', 'uuid', Rule::exists('organization_design_studies', 'id')->where('organization_id', $organizationId)],
        ];
    }
}
