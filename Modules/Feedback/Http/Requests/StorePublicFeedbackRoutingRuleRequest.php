<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Feedback\Application\Services\PublicFeedbackRoutingService;
use Modules\Feedback\Contracts\Data\PublicTipType;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;

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
            // The relevant work unit a rule is narrowed to: only on an institution's rule (a city-wide rule has no units).
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id', Rule::prohibitedIf(fn (): bool => ! $this->filled('organization_id'))],
            'handler_type' => ['required', Rule::in(PublicFeedbackHandlerType::values())],
            'handler_id' => ['required', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // ADR 0033: the ladder level this rule serves, and the days its handler has to act.
            'escalation_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'response_days' => ['nullable', 'integer', 'min:1', 'max:365'],
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
            // The module's address decides the type (/tip-off-admin or /complaint-admin).
            'submission_type' => in_array($this->route('submission_type'), ['suggestion', 'complaint'], true) ? $this->route('submission_type') : ($this->filled('submission_type') ? $this->input('submission_type') : null),
            'tip_type' => $this->filled('tip_type') ? $this->input('tip_type') : null,
            'organization_unit_id' => $this->filled('organization_unit_id') ? $this->input('organization_unit_id') : null,
            'response_days' => $this->filled('response_days') ? $this->input('response_days') : null,
            'effective_from' => $this->filled('effective_from') ? $this->input('effective_from') : null,
            'effective_to' => $this->filled('effective_to') ? $this->input('effective_to') : null,
        ]);
    }
}
