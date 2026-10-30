<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\ReasonCategory;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;

/** Accepts a disciplinary decision as a Section I row (D16). */
final class AcceptSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision_id' => ['required', 'uuid'],
            'role_category' => ['required', Rule::enum(RoleCategory::class)->except([RoleCategory::Customer])],
            'reason_category' => ['required', Rule::enum(ReasonCategory::class)],
            'reason_detail' => ['required', 'string', 'min:5', 'max:5000'],
            'office_name' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'repeat_confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
