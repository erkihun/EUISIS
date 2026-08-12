<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EntitlementStatus;
use App\Models\CardVerification;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\EmployeeServiceEligibilityService;
use Illuminate\Http\Request;

readonly class VerifyCardForServiceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private EmployeeServiceEligibilityService $serviceEligibility,
    ) {}

    public function execute(string $token, ServiceType $serviceType, ?ServiceProvider $provider, ?User $actor = null, ?Request $request = null): array
    {
        // Token format: "<card_uuid>|<raw_token>" — pipe-separated, no PII
        [$cardId, $rawToken] = array_pad(explode('|', $token, 2), 2, null);

        // Lookup by SHA-256 hash (deterministic, non-bcrypt)
        $tokenHash = $rawToken !== null ? hash('sha256', $rawToken) : null;
        $card = ($cardId !== null && $tokenHash !== null)
            ? IdCard::query()
                ->with('employee.currentAssignment')
                ->where('id', $cardId)
                ->where('token_hash', $tokenHash)
                ->first()
            : null;

        if ($card === null) {
            return $this->deny('invalid_token', null, $serviceType, $provider, $actor, $request);
        }

        $serviceEligibility = $this->serviceEligibility->check(
            $card->employee,
            $card,
            $serviceType->code,
            $actor,
            $provider?->id,
            $request,
        );

        if (! $serviceEligibility['eligible']) {
            return $this->deny($serviceEligibility['reason_code'], $card, $serviceType, $provider, $actor, $request);
        }

        if ($card->employee->currentAssignment === null) {
            return $this->deny('no_active_assignment', $card, $serviceType, $provider, $actor, $request);
        }

        if ($provider !== null && $provider->service_type_id !== $serviceType->id) {
            return $this->deny('service_not_allowed', $card, $serviceType, $provider, $actor, $request);
        }

        if ($provider !== null && $provider->status !== 'active') {
            return $this->deny('provider_inactive', $card, $serviceType, $provider, $actor, $request);
        }

        if (
            $provider !== null
            && $actor !== null
            && $actor->hasRole(['Service Provider User', 'Settlement Officer'])
            && ! $actor->hasRole(['Super Admin', 'City Admin'])
        ) {
            $allowedForProvider = $actor->organizationScopes->contains(
                fn ($scope) => ($scope->service_provider_id === null || $scope->service_provider_id === $provider->id)
                    && ($scope->service_type_id === null || $scope->service_type_id === $serviceType->id)
            );

            if (! $allowedForProvider) {
                return $this->deny('provider_scope_denied', $card, $serviceType, $provider, $actor, $request);
            }
        }

        $entitlement = Entitlement::query()
            ->where('employee_id', $card->employee_id)
            ->where('service_type_id', $serviceType->id)
            ->when($provider !== null, fn ($query) => $query->where(function ($nested) use ($provider): void {
                $nested->whereNull('service_provider_id')->orWhere('service_provider_id', $provider->id);
            }))
            ->where('status', EntitlementStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString());
            })
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->first();

        if ($entitlement === null) {
            return $this->deny('entitlement_missing', $card, $serviceType, $provider, $actor, $request);
        }

        if ($entitlement->quota_limit !== null && $entitlement->quota_used >= $entitlement->quota_limit) {
            return $this->deny('quota_exhausted', $card, $serviceType, $provider, $actor, $request);
        }

        $response = [
            'allowed' => true,
            'result_code' => 'verified',
            'card_status' => $card->status->value,
            'employee_status' => $card->employee->status->value,
            'service_type' => $serviceType->code,
            'quota_remaining' => $entitlement->quota_limit !== null ? max(0, $entitlement->quota_limit - $entitlement->quota_used) : null,
        ];

        CardVerification::query()->create([
            'id_card_id' => $card->id,
            'service_type_id' => $serviceType->id,
            'service_provider_id' => $provider?->id,
            'result_code' => 'verified',
            'allowed' => true,
            'request_ip' => $request?->ip(),
            'request_user_agent' => $request?->userAgent(),
            'response_payload' => $response,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VerificationPerformed,
            $actor,
            $card,
            $card->employee->currentAssignment?->organization_id,
            newValues: $response,
            request: $request,
        );

        return $response;
    }

    private function deny(string $resultCode, ?IdCard $card, ServiceType $serviceType, ?ServiceProvider $provider, ?User $actor, ?Request $request): array
    {
        $messageKey = "service-eligibility.reasons.{$resultCode}";
        $localizedMessage = __($messageKey);
        $response = [
            'allowed' => false,
            'result_code' => $resultCode,
            'denial_reason' => $localizedMessage === $messageKey
                ? str_replace('_', ' ', $resultCode)
                : $localizedMessage,
            'card_status' => $card?->status?->value,
            'employee_status' => $card?->employee?->status?->value,
            'service_type' => $serviceType->code,
        ];

        CardVerification::query()->create([
            'id_card_id' => $card?->id,
            'service_type_id' => $serviceType->id,
            'service_provider_id' => $provider?->id,
            'result_code' => $resultCode,
            'allowed' => false,
            'request_ip' => $request?->ip(),
            'request_user_agent' => $request?->userAgent(),
            'response_payload' => $response,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VerificationPerformed,
            $actor,
            $card,
            $card?->employee?->currentAssignment?->organization_id,
            newValues: $response,
            request: $request,
        );

        return $response;
    }
}
