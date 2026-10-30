<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\ServiceBeneficiaryType;
use App\Models\ServiceClassification;
use Illuminate\Validation\Rule;
use Modules\Entitlements\Domain\Enums\ServiceDeliveryChannel;

/** The service fields a designer writes, shared by store, update and new-version requests. */
final class ServiceRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $organizationId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
            // The city's own classification: an active LOCAL entry.
            'service_classification_id' => ['nullable', 'uuid', Rule::exists('service_classifications', 'id')->where('scheme', ServiceClassification::SCHEME_LOCAL)->where('is_active', true)],
            // An external CPC reference, when the official dataset is loaded.
            'cpc_classification_id' => ['nullable', 'uuid', Rule::exists('service_classifications', 'id')->where('scheme', ServiceClassification::SCHEME_CPC)->where('is_active', true)],
            'beneficiary_type' => ['nullable', Rule::in(ServiceBeneficiaryType::values())],
            'delivery_channel' => ['nullable', Rule::in(ServiceDeliveryChannel::values())],
            'legal_basis' => ['nullable', 'string', 'max:255'],
            'owner_organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $organizationId)],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'function_ids' => ['sometimes', 'array', 'max:100'],
            'function_ids.*' => ['uuid', Rule::exists('organization_functions', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'isic_activity_ids' => ['sometimes', 'array', 'max:20'],
            'isic_activity_ids.*' => ['uuid', 'exists:isic_activities,id'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'code' => __('organization-design.fields.code'),
            'name_en' => __('organization-design.fields.name_en'),
            'service_classification_id' => __('organization-design.fields.service_classification'),
            'cpc_classification_id' => __('organization-design.fields.cpc_classification'),
            'function_ids' => __('organization-design.fields.functions'),
            'effective_from' => __('organization-design.fields.effective_from'),
            'effective_to' => __('organization-design.fields.effective_to'),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'function_ids.*.exists' => __('organization-design.service_function_foreign'),
            'service_classification_id.exists' => __('organization-design.service_classification_invalid'),
            'cpc_classification_id.exists' => __('organization-design.cpc_classification_invalid'),
        ];
    }
}
