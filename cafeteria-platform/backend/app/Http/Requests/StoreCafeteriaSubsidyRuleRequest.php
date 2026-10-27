<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CafeteriaSubsidyRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCafeteriaSubsidyRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CafeteriaSubsidyRule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:cafeteria_subsidy_rules,code', 'regex:/^[A-Z0-9_-]+$/'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'subsidy_amount' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'applies_to' => ['required', 'in:all_employees,organization,employee_type,selected_employees'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id', 'required_if:applies_to,organization'],
            'employee_type' => ['nullable', 'string', 'max:100', 'required_if:applies_to,employee_type'],
            'is_active' => ['boolean'],
            'exclude_weekends' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
