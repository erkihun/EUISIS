<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transfers.applications.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'announcement_id' => ['required', 'uuid', 'exists:transfer_announcements,id'],
            'applicant_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
