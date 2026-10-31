<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;

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
            // Executive Situation Room rules: empty switches the rule off.
            'sla_days_feedback' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'sla_days_suggestion' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'sla_days_complaint' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'spike_threshold_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'spike_minimum_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'repeat_threshold_count' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:10000'],
        ];
    }
}
