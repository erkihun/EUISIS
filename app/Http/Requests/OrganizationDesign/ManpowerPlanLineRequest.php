<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Enums\WorkloadSourceType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A line of a draft plan: one unit (or proposed unit) × occupation × optional work item, with its inputs. */
class ManpowerPlanLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->plan()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->plan();
        $line = $this->route('line');
        $partial = $line instanceof ManpowerPlanLine;
        $organizationId = $plan->organization_id;

        return [
            'organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $organizationId), Rule::requiredIf(fn () => ! $partial && ! $this->filled('unit_design_proposal_id'))],
            'unit_design_proposal_id' => ['nullable', 'uuid', Rule::exists('unit_design_proposals', 'id')->where('organization_id', $organizationId), Rule::prohibitedIf(fn () => $this->filled('organization_unit_id'))],
            'activity_id' => ['nullable', 'uuid', Rule::exists('process_activities', 'id')->where('organization_id', $organizationId)],
            'task_id' => ['nullable', 'uuid', Rule::exists('process_tasks', 'id')->where('organization_id', $organizationId)],
            'occupation_id' => [$partial ? 'sometimes' : 'required', 'uuid', Rule::exists('occupations', 'id')->where('is_active', true)],
            'profession_id' => ['nullable', 'uuid', 'exists:professions,id'],
            'methodology_id' => ['nullable', 'uuid', Rule::exists('workforce_planning_methodologies', 'id')->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])],
            'inputs' => ['nullable', 'array', 'max:20'],
            'inputs.*' => ['nullable', 'string', 'max:40', 'regex:/^-?\d+(\.\d+)?$/'],
            'approved_headcount' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'adjustment_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'workload_source_type' => ['nullable', Rule::in(WorkloadSourceType::values())],
            'workload_source_reference' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'organization_unit_id' => __('organization-design.fields.organization_unit'),
            'occupation_id' => __('organization-design.fields.occupation'),
            'inputs.*' => __('organization-design.fields.inputs'),
        ];
    }

    public function plan(): ManpowerPlan
    {
        $line = $this->route('line');
        if ($line instanceof ManpowerPlanLine) {
            return $line->plan;
        }
        $plan = $this->route('plan');

        return $plan instanceof ManpowerPlan ? $plan : abort(404);
    }
}
