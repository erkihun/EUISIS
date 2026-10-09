<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'require_same_position' => $this->require_same_position,
            'require_same_grade' => $this->require_same_grade,
            'require_same_salary' => $this->require_same_salary,
            'allow_cross_institution' => $this->allow_cross_institution,
            'allow_exceptional_override' => $this->allow_exceptional_override,
            'override_approver_roles' => $this->override_approver_roles ?? [],
            'required_documents' => $this->required_documents ?? [],
            'minimum_service_months' => $this->minimum_service_months,
            'releasing_consent_required' => $this->releasing_consent_required,
            'receiving_consent_required' => $this->receiving_consent_required,
            'final_approval_required' => $this->final_approval_required,
            'card_reprint_policy' => $this->card_reprint_policy?->value,
            'service_recalculation_policy' => $this->service_recalculation_policy?->value,
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_by' => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null,
        ];
    }
}
