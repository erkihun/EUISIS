<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The responsibilities of a unit or a proposed unit, replaced as a set. */
class ResponsibilitySyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $subject instanceof OrganizationUnit
            ? $user->can('mapUnit', [OrganizationDesignStudy::class, $subject])
            : $user->can('update', $subject->study);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:500'],
            'items.*.object_type' => ['required', Rule::in(UnitResponsibility::OBJECT_TYPES)],
            'items.*.object_id' => ['required', 'uuid'],
            'items.*.responsibility_type_id' => ['required', 'uuid', 'exists:responsibility_types,id'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function subject(): OrganizationUnit|UnitDesignProposal
    {
        $id = (string) $this->route('subject');
        $model = match ((string) $this->route('subjectType')) {
            'unit' => OrganizationUnit::query()->find($id),
            'proposal' => UnitDesignProposal::query()->find($id),
            default => null,
        };

        return $model ?? abort(404);
    }
}
