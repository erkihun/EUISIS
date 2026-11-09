<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use App\Enums\OrganizationalChangeAttachmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Supporting documents. Files are validated by extension and size, then stored privately. */
class StoreOrganizationalChangeAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('uploadAttachment', $this->route('organizationalChangeRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $config = config('organizational_change.attachments');

        return [
            'document_type' => ['required', Rule::in(OrganizationalChangeAttachmentType::values())],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'document_date' => ['nullable', 'date'],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', $config['mimes']),
                'max:'.$config['max_kilobytes'],
            ],
        ];
    }
}
