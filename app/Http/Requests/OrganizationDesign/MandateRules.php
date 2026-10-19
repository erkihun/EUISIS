<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

/** The mandate fields a designer writes, shared by store, update and new-version requests. */
final class MandateRules
{
    /** @return array<string, list<string>> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'legal_instrument_type_id' => [$required, 'uuid', 'exists:legal_instrument_types,id'],
            'legal_instrument_number' => [$required, 'string', 'max:100'],
            'legal_reference' => ['nullable', 'string', 'max:255'],
            'article_reference' => ['nullable', 'string', 'max:255'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'legal_source_id' => ['nullable', 'uuid', 'exists:legal_sources,id'],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'code' => __('organization-design.fields.code'),
            'title_en' => __('organization-design.fields.title_en'),
            'title_am' => __('organization-design.fields.title_am'),
            'legal_instrument_type_id' => __('organization-design.fields.legal_instrument_type'),
            'legal_instrument_number' => __('organization-design.fields.legal_instrument_number'),
            'effective_from' => __('organization-design.fields.effective_from'),
            'effective_to' => __('organization-design.fields.effective_to'),
        ];
    }
}
