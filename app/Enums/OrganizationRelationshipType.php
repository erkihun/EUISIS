<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationRelationshipType: string
{
    case ReportsTo = 'reports_to';
    case GeographicallyUnder = 'geographically_under';
    case ServiceScope = 'service_scope';
    case Oversight = 'oversight';
    case StructuralParent = 'structural_parent';
    case FunctionalReporting = 'functional_reporting';
    case TechnicalSupervision = 'technical_supervision';
    case AdministrativeReporting = 'administrative_reporting';
    case Coordination = 'coordination';
    case ServiceDelivery = 'service_delivery';
    case BudgetReporting = 'budget_reporting';
    case TemporaryAssignment = 'temporary_assignment';
    case DottedLineReporting = 'dotted_line_reporting';
    case Other = 'other';

    public function label(): string
    {
        return __('relationships.types.'.$this->value);
    }

    public function isSecondary(): bool
    {
        return $this !== self::StructuralParent;
    }
}
