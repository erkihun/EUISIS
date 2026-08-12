<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class EmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name_en' => filled($this->input('name_en')) ? trim((string) $this->input('name_en')) : null,
        ]);

        if ($this->has('national_id')) {
            $nid = filled($this->input('national_id')) ? trim((string) $this->input('national_id')) : null;
            $this->merge([
                'national_id' => $nid,
                'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
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
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:32'],
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
