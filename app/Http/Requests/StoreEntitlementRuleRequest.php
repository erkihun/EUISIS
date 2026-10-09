<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EntitlementRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntitlementRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EntitlementRule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'rule_definition' => ['nullable', 'array'],
            'rule_definition.quota_limit' => ['nullable', 'integer', 'min:0'],
            'rule_definition.period_days' => ['nullable', 'integer', 'min:1'],
            'rule_definition.notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
