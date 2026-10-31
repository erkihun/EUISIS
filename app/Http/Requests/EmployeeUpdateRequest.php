<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Http\Requests\Concerns\ValidatesEmployeeStatistics;
use App\Support\Security\NationalIdHash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class EmployeeUpdateRequest extends FormRequest
{
    use ValidatesEmployeeStatistics;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmployeeStatistics();

        $this->merge([
            'name_en' => filled($this->input('name_en')) ? trim((string) $this->input('name_en')) : null,
        ]);

        // The edit form receives a blank national ID when the editor may not see it
        // (EmployeeDetailResource), so a blank from such an editor is not a deliberate
        // clear: drop the field and keep the stored value and its uniqueness hash.
        if (! ($this->user()?->can('employees.viewPii') ?? false)) {
            $this->offsetUnset('national_id');
            $this->offsetUnset('national_id_hash');

            return;
        }

        if ($this->has('national_id')) {
            $nid = filled($this->input('national_id')) ? trim((string) $this->input('national_id')) : null;
            $this->merge([
                'national_id' => $nid,
                'national_id_hash' => $nid !== null ? NationalIdHash::of($nid) : null,
            ]);
        }
    }

    public function rules(): array
    {
        $employeeId = (string) $this->route('employee')?->getKey();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', "unique:employees,email,{$employeeId},id"],
            ...$this->employeeStatisticsRules($this->route('employee')?->gender),
            'nationality' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', new Enum(EmploymentType::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-\s]+$/'],
            'status' => ['required', new Enum(EmployeeStatus::class)],
            'national_id' => ['nullable', 'string', 'size:16', 'regex:/^[0-9]{16}$/'],
            'national_id_hash' => [
                'nullable', 'string', 'size:64',
                "unique:employees,national_id_hash,{$employeeId},id",
            ],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }
}
