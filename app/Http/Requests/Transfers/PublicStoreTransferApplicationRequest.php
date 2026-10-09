<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use Illuminate\Foundation\Http\FormRequest;

class PublicStoreTransferApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'announcement_position_id' => ['nullable', 'uuid', 'exists:transfer_announcement_positions,id'],
            'cover_letter' => ['nullable', 'string', 'max:3000'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'reuse_document_ids' => ['nullable', 'array'],
            'reuse_document_ids.*' => ['nullable', 'uuid', 'exists:employee_documents,id'],
        ];
    }
}
