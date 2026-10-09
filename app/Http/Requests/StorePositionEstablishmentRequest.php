<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOrganizationWithinScope;
use App\Models\PositionEstablishment;
use Illuminate\Foundation\Http\FormRequest;

class StorePositionEstablishmentRequest extends FormRequest
{
    use ValidatesOrganizationWithinScope;

    public function authorize(): bool
    {
        return $this->user()?->can('create', PositionEstablishment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id', $this->organizationWithinScopeRule()],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'position_id' => ['required', 'uuid', 'exists:positions,id'],
            'occupation_id' => ['nullable', 'uuid', 'exists:occupations,id'],
            'approved_slots' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'approval_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
