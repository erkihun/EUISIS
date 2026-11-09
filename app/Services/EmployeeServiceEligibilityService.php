<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use Illuminate\Http\Request;

final readonly class EmployeeServiceEligibilityService
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    /**
     * @return array{eligible: bool, reason_code: string|null, message: string, message_key: string|null, card_status: string|null}
     */
    public function check(
        ?Employee $employee,
        ?IdCard $card,
        string $serviceType,
        ?User $actor = null,
        ?string $providerId = null,
        ?Request $request = null,
        bool $tokenValid = true,
        ?string $attemptedBy = null,
    ): array {
        if ($employee === null) {
            return $this->deny('no_active_id_card', null, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ($employee->status !== EmployeeStatus::Active) {
            return $this->deny('employee_inactive', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ($card === null) {
            return $this->deny('no_active_id_card', $employee, null, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if (! $tokenValid || $card->qr_status === 'revoked') {
            return $this->deny('id_card_revoked', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ((string) $card->employee_id !== (string) $employee->id) {
            return $this->deny('id_card_not_active', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        $statusReason = match ($card->status) {
            CardStatus::Lost => 'id_card_lost',
            CardStatus::Replaced => 'id_card_replaced',
            CardStatus::Revoked => 'id_card_revoked',
            CardStatus::Expired => 'id_card_expired',
            CardStatus::Suspended => 'id_card_suspended',
            CardStatus::PendingPrint, CardStatus::Printed, CardStatus::Issued => 'id_card_pending',
            CardStatus::Damaged => 'id_card_not_active',
            CardStatus::Active => null,
        };

        if ($statusReason !== null) {
            return $this->deny($statusReason, $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ($card->revoked_at !== null) {
            return $this->deny('id_card_revoked', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ($card->expires_at !== null && $card->expires_at->isPast()) {
            return $this->deny('id_card_expired', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        $activeCard = $employee->activeIdCard()->first();
        if ($activeCard === null) {
            return $this->deny('no_active_id_card', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        if ((string) $activeCard->id !== (string) $card->id) {
            return $this->deny('id_card_replaced', $employee, $card, $serviceType, $actor, $providerId, $request, $attemptedBy);
        }

        return [
            'eligible' => true,
            'reason_code' => null,
            'message' => __('service-eligibility.eligible'),
            'message_key' => null,
            'card_status' => $card->status->value,
        ];
    }

    /** @return array{eligible: false, reason_code: string, message: string, message_key: string, card_status: string|null} */
    private function deny(
        string $reasonCode,
        ?Employee $employee,
        ?IdCard $card,
        string $serviceType,
        ?User $actor,
        ?string $providerId,
        ?Request $request,
        ?string $attemptedBy,
    ): array {
        $messageKey = "service-eligibility.reasons.{$reasonCode}";
        $message = __($messageKey);

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceAccessBlocked,
            $actor,
            $card ?? $employee,
            $employee?->currentAssignment?->organization_id,
            newValues: [
                'employee_id' => $employee?->id,
                'id_card_id' => $card?->id,
                'provider_id' => $providerId,
                'service_type' => $serviceType,
                'reason_code' => $reasonCode,
                'attempted_by' => $attemptedBy ?? $actor?->id,
                'attempted_at' => now()->toIso8601String(),
            ],
            reason: $message,
            request: $request,
        );

        return [
            'eligible' => false,
            'reason_code' => $reasonCode,
            'message' => $message,
            'message_key' => $messageKey,
            'card_status' => $card?->status?->value,
        ];
    }
}
