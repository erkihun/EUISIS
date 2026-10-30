<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\ReasonCategory;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Enums\SubjectType;

/** A Section I.1 row (D12, D13). The identity, office, date and duplicate rules are checked in SaveSection1Entry. */
final class SaveAccountabilityEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::enum(SubjectType::class)],
            'employee_id' => ['nullable', 'required_if:subject_type,employee', 'uuid'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'office_name' => ['nullable', 'string', 'max:255'],
            'role_category' => ['required', Rule::enum(RoleCategory::class)],
            'reason_category' => ['required', Rule::enum(ReasonCategory::class)],
            'reason_detail' => ['required', 'string', 'min:5', 'max:5000'],
            'final_action' => ['required', Rule::enum(FinalAction::class)],
            'action_detail' => ['required', 'string', 'min:5', 'max:5000'],
            'action_date' => ['required', 'date_format:Y-m-d'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'repeat_confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
