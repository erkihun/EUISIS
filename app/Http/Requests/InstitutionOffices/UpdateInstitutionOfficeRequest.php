<?php

declare(strict_types=1);

namespace App\Http\Requests\InstitutionOffices;

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use App\Models\InstitutionOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateInstitutionOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var InstitutionOffice|null $office */
        $office = $this->route('institutionOffice');

        return $office !== null
            ? ($this->user()?->can('update', $office) ?? false)
            : false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'structural_organization_id' => $this->input('structural_organization_id') ?: null,
            'geographic_organization_id' => $this->input('geographic_organization_id') ?: null,
            'parent_office_id' => $this->input('parent_office_id') ?: null,
            'name_am' => $this->input('name_am') ?: null,
            'short_name_en' => $this->input('short_name_en') ?: null,
            'short_name_am' => $this->input('short_name_am') ?: null,
            'opened_on' => $this->input('opened_on') ?: null,
            'closed_on' => $this->input('closed_on') ?: null,
            'address_en' => $this->input('address_en') ?: null,
            'address_am' => $this->input('address_am') ?: null,
            'phone_number' => $this->input('phone_number') ?: null,
            'email' => $this->input('email') ?: null,
            'notes' => $this->input('notes') ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var InstitutionOffice $office */
        $office = $this->route('institutionOffice');

        return [
            'institution_id' => ['sometimes', 'uuid', 'exists:organizations,id'],
            'structural_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'geographic_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'parent_office_id' => ['nullable', 'uuid', 'exists:institution_offices,id'],
            'office_level' => ['sometimes', new Enum(InstitutionOfficeLevel::class)],
            'office_code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('institution_offices', 'office_code')->ignore($office->id),
            ],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'short_name_en' => ['nullable', 'string', 'max:100'],
            'short_name_am' => ['nullable', 'string', 'max:100'],
            'assigned_scope_type' => ['sometimes', 'string', 'in:self,subtree'],
            'is_head_office' => ['sometimes', 'boolean'],
            'status' => ['sometimes', new Enum(InstitutionOfficeStatus::class)],
            'opened_on' => ['nullable', 'date'],
            'closed_on' => ['nullable', 'date', 'after_or_equal:opened_on'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_am' => ['nullable', 'string', 'max:500'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parentOfficeId = $this->string('parent_office_id')->toString();

                /** @var InstitutionOffice $office */
                $office = $this->route('institutionOffice');
                $institutionId = $this->string('institution_id')->toString() ?: $office->institution_id;

                if ($parentOfficeId === '') {
                    return;
                }

                // Cannot set self as parent
                if ($parentOfficeId === $office->id) {
                    $validator->errors()->add(
                        'parent_office_id',
                        __('institution-offices.validation.cannot_be_own_parent'),
                    );

                    return;
                }

                $parentOffice = InstitutionOffice::query()->find($parentOfficeId);

                if ($parentOffice !== null && $parentOffice->institution_id !== $institutionId) {
                    $validator->errors()->add(
                        'parent_office_id',
                        __('institution-offices.validation.parent_must_same_institution'),
                    );
                }
            },
        ];
    }
}
