<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Abilities granted to API tokens issued to external applications.
 *
 * A token carries only the scopes its application was approved for; endpoints
 * assert the specific scope they need. The legacy `provider:access` ability and
 * Sanctum's wildcard `*` remain accepted so existing provider-portal tokens
 * keep working — see EnsureApiScope.
 */
enum ApiScope: string
{
    case NfcVerify = 'nfc.verify';
    case NfcServiceEligibility = 'nfc.service_eligibility';
    case NfcServiceTransactionsCreate = 'nfc.service_transactions.create';
    case IdCardsVerify = 'id_cards.verify';
    case EmployeesBasicVerify = 'employees.basic_verify';
    case ServiceEligibilityCheck = 'service_eligibility.check';
    case ServiceTransactionsCreate = 'service_transactions.create';
    case ReportsReadLimited = 'reports.read_limited';
    case OrganizationStructureRead = 'organization_structure.read';
    case EmployeesBasicRead = 'employees.basic_read';
    case OrganizationsRead = 'organizations.read';
    case OrganizationUnitsRead = 'organization_units.read';
    case PositionsRead = 'positions.read';
    case EmployeeAssignmentsRead = 'employee_assignments.read';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    public function label(): string
    {
        return __('api_scopes.'.$this->value);
    }
}
