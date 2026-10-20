<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\LegacyBaseline;

/** The baseline fields a designer writes, shared by store and update. Status and the audit fields are never written here. */
final class LegacyBaselineRules
{
    /** @return array<string, list<string>> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'origin' => ['nullable', 'string', 'in:'.implode(',', LegacyBaseline::ORIGINS)],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'hierarchy_version_id' => ['nullable', 'uuid', 'exists:hierarchy_versions,id'],
            'legal_source_id' => ['nullable', 'uuid', 'exists:legal_sources,id'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'title_en' => __('organization-design.fields.title_en'),
            'title_am' => __('organization-design.fields.title_am'),
            'effective_date' => __('organization-design.fields.effective_date'),
            'hierarchy_version_id' => __('organization-design.fields.hierarchy_version'),
            'legal_source_id' => __('organization-design.fields.legal_source'),
        ];
    }
}
