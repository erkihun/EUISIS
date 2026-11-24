<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Reporting\Application\Support\ReportingSettings;

/**
 * An uploaded file: present, within the size limit, of a named kind. Whether
 * it really is what its name says is decided by the upload inspector, which
 * reads the content; nothing here trusts the extension or the declared type.
 */
final class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) app(ReportingSettings::class)->get('max_upload_kb')],
            'kind' => ['required', Rule::in(['report_document', 'annex', 'evidence'])],
            'requirement_key' => ['nullable', 'string', 'max:60'],
        ];
    }
}
