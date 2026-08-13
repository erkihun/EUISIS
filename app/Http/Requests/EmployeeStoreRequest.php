<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\EmployeeAssignment;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class EmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $nid = filled($this->input('national_id')) ? trim((string) $this->input('national_id')) : null;
        $this->merge([
            'national_id' => $nid,
            'name_en' => filled($this->input('name_en')) ? trim((string) $this->input('name_en')) : null,
            // Uniqueness is enforced via the deterministic hash because the
            // raw national_id column is stored encrypted.
            'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->string('organization_id')->toString();
        $organizationUnitId = $this->string('organization_unit_id')->toString();

        // An employee may only be placed into an ACTIVE organization, unit and
        // position — inactive structures are not selectable in the UI and must
        // be rejected here too, since the backend is the source of truth.
        $positionRule = Rule::exists('positions', 'id')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($organizationUnitId !== '') {
            $positionRule->where('organization_unit_id', $organizationUnitId);
        }

        return [
            'employee_number' => ['nullable', 'string', 'max:255', 'unique:employees,employee_number'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'size:16', 'regex:/^[0-9]{16}$/'],
            'national_id_hash' => ['nullable', 'string', 'size:64', 'unique:employees,national_id_hash'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:32'],
            'status' => ['required', new Enum(EmployeeStatus::class)],
            'organization_id' => [
                'required',
                'uuid',
                Rule::exists('organizations', 'id')
                    ->where('status', OrganizationStatus::Active->value)
                    ->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(OrganizationScopeService::class)->canAccess($this->user(), (string) $value)) {
                        $fail(__('validation.organization_scope_denied'));
                    }
                },
            ],
            'organization_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', OrganizationUnitStatus::Active->value)
                    ->whereNull('deleted_at'),
            ],
            'hierarchy_version_id' => ['nullable', 'uuid', 'exists:hierarchy_versions,id'],
            'position_id' => [
                'nullable',
                'uuid',
                $positionRule,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $occupied = EmployeeAssignment::query()
                        ->where('position_id', $value)
                        ->where('is_current', true)
                        ->where('assignment_status', AssignmentStatus::Active)
                        ->exists();
                    if ($occupied) {
                        $fail(__('validation.position_already_occupied'));
                    }
                },
            ],
            'position_title' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
        ];
    }
}
