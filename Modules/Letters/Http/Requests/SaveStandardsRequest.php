<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;

/** An institution's reply-time standard, or the platform default when organization_id is empty. */
final class SaveStandardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $owner = $this->input('organization_id');

        return app(LettersAccess::class)->canManageStandards($user, is_string($owner) && $owner !== '' ? $owner : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'reply_days_normal' => ['required', 'integer', 'min:1', 'max:120'],
            'reply_days_urgent' => ['required', 'integer', 'min:1', 'max:120'],
            'reply_days_very_urgent' => ['required', 'integer', 'min:0', 'max:120'],
            'interim_reply_days' => ['required', 'integer', 'min:0', 'max:60'],
            'escalation_after_days' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }
}
