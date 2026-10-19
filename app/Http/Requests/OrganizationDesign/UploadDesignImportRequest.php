<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\DesignImportBatch;
use App\Services\OrganizationDesign\Import\DesignImportDefinitions;
use App\Services\OrganizationDesign\Import\DesignImportService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

/** A spreadsheet or CSV into staging. `mimes` checks the content, `extensions` the name; macros are never run. */
class UploadDesignImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', DesignImportBatch::class)) {
            return false;
        }

        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $allowed = implode(',', DesignImportService::ALLOWED_EXTENSIONS);

        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'entity_type' => ['required', 'string', 'in:'.implode(',', DesignImportDefinitions::TYPES)],
            'baseline_id' => ['nullable', 'uuid', 'exists:legacy_baselines,id'],
            'file' => ['required', 'file', "mimes:{$allowed},txt", "extensions:{$allowed}", 'max:'.DesignImportService::MAX_FILE_KB],
        ];
    }
}
