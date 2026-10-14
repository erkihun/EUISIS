<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PublicFeedbackHandlerType;
use App\Enums\PublicTipType;
use App\Services\ServiceFeedback\PublicFeedbackRoutingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicFeedbackRoutingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Scope (city-wide versus one institution) is checked in the controller.
        return $this->user()?->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'target_type' => ['nullable', Rule::in(['employee', 'organization'])],
            'submission_type' => ['nullable', Rule::in(['suggestion', 'complaint'])],
            // A Tip-off type only narrows a Tip-off (suggestion) rule.
            'tip_type' => ['nullable', Rule::enum(PublicTipType::class), Rule::prohibitedIf(fn (): bool => $this->input('submission_type') === 'complaint')],
            'category_id' => ['nullable', 'uuid', 'exists:public_feedback_categories,id'],
            'handler_type' => ['required', Rule::in(PublicFeedbackHandlerType::values())],
            'handler_id' => ['required', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'organization_id' => $this->filled('organization_id') ? $this->input('organization_id') : null,
            'target_type' => $this->filled('target_type') ? $this->input('target_type') : null,
            'submission_type' => $this->filled('submission_type') ? $this->input('submission_type') : null,
            'tip_type' => $this->filled('tip_type') ? $this->input('tip_type') : null,
            'category_id' => $this->filled('category_id') ? $this->input('category_id') : null,
            'effective_from' => $this->filled('effective_from') ? $this->input('effective_from') : null,
            'effective_to' => $this->filled('effective_to') ? $this->input('effective_to') : null,
        ]);
    }
}
