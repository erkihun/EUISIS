<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Contracts\CredentialVerification;
use App\Backbone\Data\CardCredential;
use App\Backbone\Data\CredentialFormat;
use App\Backbone\Data\CredentialVerificationData;
use App\Models\Employee;
use BackedEnum;

/**
 * CredentialLookup finds the card, CardCredentialStatus decides whether it is
 * valid; this adds the holder's minimal identity and nothing else.
 */
final readonly class BackboneCredentialVerification implements CredentialVerification
{
    public function __construct(
        private CredentialLookup $lookup,
        private CardCredentialStatus $status,
    ) {}

    public function verify(string $scannedValue, CredentialFormat ...$formats): CredentialVerificationData
    {
        $credential = $this->lookup->resolveScan($scannedValue, ...($formats === [] ? [CredentialFormat::PublicReference] : $formats));

        return $credential === null ? CredentialVerificationData::notFound() : $this->answer($credential);
    }

    public function verifyCardUuid(string $cardUuid): CredentialVerificationData
    {
        $credential = $this->status->forCardUuid($cardUuid);

        return $credential === null ? CredentialVerificationData::notFound() : $this->answer($credential);
    }

    private function answer(CardCredential $credential): CredentialVerificationData
    {
        // Only the columns the answer needs: never national ID, phone, address or photo path.
        $employee = Employee::query()
            ->select(['id', 'employee_number', 'full_name', 'status', 'current_assignment_id'])
            ->with('currentAssignment:id,organization_id')
            ->find($credential->employeeUuid);

        return new CredentialVerificationData(
            valid: $credential->valid,
            status: $credential->status,
            cardUuid: $credential->cardUuid,
            employeeUuid: $employee === null ? null : (string) $employee->getKey(),
            employeeNumber: $employee?->employee_number,
            displayName: $employee?->full_name,
            employmentStatus: $employee === null ? null : ($employee->status instanceof BackedEnum ? (string) $employee->status->value : (string) $employee->status),
            organizationUuid: $employee?->currentAssignment?->organization_id === null ? null : (string) $employee->currentAssignment->organization_id,
        );
    }
}
