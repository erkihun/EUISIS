<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use App\Enums\TransferCardReprintPolicy;
use App\Enums\TransferServiceRecalculationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
            'override_approver_roles.*' => ['string', 'max:60'],
            'required_documents' => ['sometimes', 'nullable', 'array'],
            'required_documents.*' => ['string', 'max:100'],
            'minimum_service_months' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'releasing_consent_required' => ['sometimes', 'boolean'],
            'receiving_consent_required' => ['sometimes', 'boolean'],
            'final_approval_required' => ['sometimes', 'boolean'],
            'card_reprint_policy' => ['sometimes', new Enum(TransferCardReprintPolicy::class)],
            'service_recalculation_policy' => ['sometimes', new Enum(TransferServiceRecalculationPolicy::class)],
        ];
    }
}
