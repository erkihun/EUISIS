<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

/** The legal source fields a designer writes, shared by store and update. */
final class LegalSourceRules
{
    /** @return array<string, list<string>> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'source_type_id' => [$required, 'uuid', 'exists:legal_instrument_types,id'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'code' => __('organization-design.fields.code'),
            'title_en' => __('organization-design.fields.title_en'),
            'title_am' => __('organization-design.fields.title_am'),
            'source_type_id' => __('organization-design.fields.legal_instrument_type'),
            'document_number' => __('organization-design.fields.document_number'),
            'issue_date' => __('organization-design.fields.issue_date'),
            'effective_from' => __('organization-design.fields.effective_from'),
            'effective_to' => __('organization-design.fields.effective_to'),
            'source_url' => __('organization-design.fields.source_url'),
        ];
    }
}
