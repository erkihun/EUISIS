<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ServiceFeedback\PublicFeedbackSettings;
use Illuminate\Foundation\Http\FormRequest;

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
            'allow_attachments' => ['sometimes', 'boolean'],
            'feedback_enabled' => ['sometimes', 'boolean'],
            'suggestion_enabled' => ['sometimes', 'boolean'],
            'complaint_enabled' => ['sometimes', 'boolean'],
            'acknowledgement_enabled' => ['sometimes', 'boolean'],
            'rate_limit_per_source' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'rate_limit_per_ip' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
