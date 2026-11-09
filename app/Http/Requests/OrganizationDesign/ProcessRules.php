<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\ProcessType;
use Illuminate\Validation\Rule;

/** The process fields a designer writes, shared by store, update and new-version requests. */
final class ProcessRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $organizationId, bool $partial = false, ?string $ignoreLineage = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'service_id' => [$required, 'uuid', Rule::exists('services', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('processes', 'code')->where('organization_id', $organizationId)->whereNull('deleted_at')
                    ->when($ignoreLineage !== null, fn ($rule) => $rule->where(fn ($q) => $q->where('lineage_key', '<>', $ignoreLineage)))],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'process_type' => [$required, Rule::in(ProcessType::values())],
            'owner_organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $organizationId)],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'service_id' => __('organization-design.fields.service'),
            'code' => __('organization-design.fields.code'),
            'name_en' => __('organization-design.fields.name_en'),
            'effective_from' => __('organization-design.fields.effective_from'),
            'effective_to' => __('organization-design.fields.effective_to'),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['service_id.exists' => __('organization-design.process_service_foreign')];
    }
}
