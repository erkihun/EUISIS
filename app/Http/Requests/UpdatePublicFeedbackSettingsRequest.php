<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SubmitterIdentityMode;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePublicFeedbackSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(PublicFeedbackSettings::class)->canManage($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'identity_mode' => ['sometimes', Rule::in(SubmitterIdentityMode::values())],
            'allow_attachments' => ['sometimes', 'boolean'],
            'feedback_enabled' => ['sometimes', 'boolean'],
            'suggestion_enabled' => ['sometimes', 'boolean'],
            'complaint_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
