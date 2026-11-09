<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionWorkLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Work links or written requirements of a position, replaced as sets (consistency checked in PositionProfileService). */
class PositionWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = $this->route('position');

        return $position instanceof Position && ($this->user()?->can('designPosition', [PositionProfile::class, $position]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'max:200'],
            'items.*.object_type' => ['required', Rule::in(PositionWorkLink::OBJECT_TYPES)],
            'items.*.object_id' => ['required', 'uuid'],
            'items.*.is_primary' => ['nullable', 'boolean'],
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
}
