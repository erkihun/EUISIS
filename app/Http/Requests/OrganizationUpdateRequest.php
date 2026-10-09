<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationStatus;
use App\Models\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class OrganizationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $this->user()?->can('update', $organization) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name_am' => $this->input('name_am') ?: null,
            'legal_basis_ref' => $this->input('legal_basis_ref') ?: null,
            'effective_from' => $this->input('effective_from') ?: null,
            'effective_to' => $this->input('effective_to') ?: null,
            'branding_primary_color' => $this->input('branding_primary_color') ?: null,
            'branding_secondary_color' => $this->input('branding_secondary_color') ?: null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->route('organization')?->id;

        return [
            'organization_type_id' => ['required', 'uuid', 'exists:organization_types,id'],
            'code' => ['required', 'string', 'max:255', Rule::unique('organizations', 'code')->ignore($organizationId)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'legal_basis_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['required', new Enum(OrganizationStatus::class)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'branding_primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $organizationTypeId = $this->string('organization_type_id')->toString();

                if ($organizationTypeId === '') {
                    return;
                }

                $organizationType = OrganizationType::query()->find($organizationTypeId);

                if ($organizationType !== null && ! $organizationType->is_active) {
                    $validator->errors()->add('organization_type_id', __('organizations.inactive_organization_type'));
                }
            },
        ];
    }
}
