<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Transfer\Domain\Enums\TransferCardReprintPolicy;
use Modules\Transfer\Domain\Enums\TransferServiceRecalculationPolicy;

class UpdateTransferSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transfers.settings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'require_same_position' => ['sometimes', 'boolean'],
            'require_same_grade' => ['sometimes', 'boolean'],
            'require_same_salary' => ['sometimes', 'boolean'],
            'allow_cross_institution' => ['sometimes', 'boolean'],
            'allow_exceptional_override' => ['sometimes', 'boolean'],
            'override_approver_roles' => ['sometimes', 'nullable', 'array'],
            'override_approver_roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
            'required_documents' => ['sometimes', 'nullable', 'array'],
            'required_documents.*' => ['string', 'distinct', 'max:100'],
            'minimum_service_months' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'releasing_consent_required' => ['sometimes', 'boolean'],
            'receiving_consent_required' => ['sometimes', 'boolean'],
            'final_approval_required' => ['sometimes', 'boolean'],
            'card_reprint_policy' => ['sometimes', new Enum(TransferCardReprintPolicy::class)],
            'service_recalculation_policy' => ['sometimes', new Enum(TransferServiceRecalculationPolicy::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) __('transfers.attributes');
    }
}
