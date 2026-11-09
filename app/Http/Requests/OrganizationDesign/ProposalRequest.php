<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\OrganizationDesignStudy;
use App\Models\UnitDesignProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A unit proposal under a study; the consistency rules (type ↔ existing unit, parents, cycles) live in DesignStudyService. */
class ProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->study()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $study = $this->study();
        $partial = $this->route('proposal') instanceof UnitDesignProposal;
        $required = $partial ? 'sometimes' : 'required';
        $ownUnit = Rule::exists('organization_units', 'id')->where('organization_id', $study->organization_id);

        return [
            'proposal_type' => [$required, Rule::in(UnitDesignProposal::TYPES)],
            'existing_unit_id' => ['nullable', 'uuid', $ownUnit],
            'parent_existing_unit_id' => ['nullable', 'uuid', $ownUnit],
            'parent_proposed_unit_id' => ['nullable', 'uuid', Rule::exists('unit_design_proposals', 'id')->where('study_id', $study->getKey())],
            'proposed_unit_type_id' => ['nullable', 'uuid', 'exists:organization_unit_types,id', Rule::requiredIf(fn () => ! $partial && $this->input('proposal_type', 'create') === 'create')],
            'unit_type' => ['nullable', 'string', 'max:50'],
            // A new unit needs a name; a change to an existing unit defaults to that unit's name.
            'name_en' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => ! $partial && $this->input('proposal_type', 'create') === 'create')],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'design_rationale' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'existing_unit_id' => __('organization-design.fields.existing_unit'),
            'parent_existing_unit_id' => __('organization-design.fields.parent_unit'),
            'parent_proposed_unit_id' => __('organization-design.fields.parent_unit'),
            'proposed_unit_type_id' => __('organization-design.fields.unit_type'),
            'name_en' => __('organization-design.fields.name_en'),
        ];
    }

    public function study(): OrganizationDesignStudy
    {
        $proposal = $this->route('proposal');
        if ($proposal instanceof UnitDesignProposal) {
            return $proposal->study;
        }
        $study = $this->route('study');

        return $study instanceof OrganizationDesignStudy ? $study : abort(404);
    }
}
