<?php

declare(strict_types=1);

namespace App\Enums;

enum CodeRuleEntityType: string
{
    case Organization = 'organization';
    case OrganizationUnit = 'organization_unit';
    case OrganizationUnitType = 'organization_unit_type';
    case OrganizationType = 'organization_type';
    case Employee = 'employee';
    case Position = 'position';
    case EmployeePosition = 'employee_position';
    case IdCard = 'id_card';
    case CardRequest = 'card_request';
    case ServiceProvider = 'service_provider';
    case ServiceType = 'service_type';
    case EntitlementRule = 'entitlement_rule';
    case TransportRoute = 'transport_route';
    case TransportPlan = 'transport_plan';
    case CouponProgram = 'coupon_program';
    case MealPlan = 'meal_plan';
    case ApprovalWorkflow = 'approval_workflow';
    case ApiClient = 'api_client';
    case DeviceBinding = 'device_binding';
    case SupportTicket = 'support_ticket';
    case Occupation = 'occupation';
    case CafeteriaProvider = 'cafeteria_provider';
    case CafeteriaSubsidyRule = 'cafeteria_subsidy_rule';
    case CafeteriaTransaction = 'cafeteria_transaction';
    case CafeteriaReport = 'cafeteria_report';
    case PositionEstablishment = 'position_establishment';
    case VacancyAnnouncement = 'vacancy_announcement';
    case VacancyApplication = 'vacancy_application';
    case TransferApplication = 'transfer_application';
    case InstitutionOffice = 'institution_office';
    case GrievanceCase = 'grievance_case';
    case GrievanceDecision = 'grievance_decision';
    case GrievanceLetter = 'grievance_letter';
}
