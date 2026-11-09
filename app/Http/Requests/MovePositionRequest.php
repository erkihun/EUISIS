<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationUnitStatus;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Position $position */
        $position = $this->route('position');

        return $this->user()?->can('move', $position) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Position $position */
        $position = $this->route('position');

        return [
            'target_organization_unit_id' => [
                'required',
                'uuid',
                Rule::notIn(array_filter([$position->organization_unit_id])),
                Rule::exists('organization_units', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $position->organization_id)
                        ->where('status', OrganizationUnitStatus::Active->value)
                        ->whereNull('deleted_at'),
                ),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_organization_unit_id.required' => __('positions.target_unit_required'),
            'target_organization_unit_id.not_in' => __('positions.same_target_unit'),
            'target_organization_unit_id.exists' => __('positions.invalid_target_unit'),
            'reason.required' => __('positions.move_reason_required'),
        ];
    }
}
