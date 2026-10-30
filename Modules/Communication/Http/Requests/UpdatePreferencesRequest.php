<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A person's own Communication preferences. */
class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'allow_direct_messages' => ['sometimes', 'boolean'],
            'show_online_status' => ['sometimes', 'boolean'],
            'show_last_seen' => ['sometimes', 'boolean'],
            'email_announcements' => ['sometimes', 'boolean'],
            'sms_emergency_announcements' => ['sometimes', 'boolean'],
        ];
    }
}
