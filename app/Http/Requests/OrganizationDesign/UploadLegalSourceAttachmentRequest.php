<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Services\OrganizationDesign\LegalSourceService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A source document. `mimes` checks the real content, `extensions` refuses a
 * misleading client file name; spreadsheets are stored, never executed.
 */
class UploadLegalSourceAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $this->user()?->can('manageAttachments', $source) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $allowed = implode(',', LegalSourceService::ALLOWED_EXTENSIONS);

        return [
            'file' => ['required', 'file', "mimes:{$allowed}", "extensions:{$allowed}", 'max:'.LegalSourceService::MAX_ATTACHMENT_KB],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
