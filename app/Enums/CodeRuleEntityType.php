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
    case PublicServiceComplaint = 'public_service_complaint';
    case PublicTipOff = 'public_tip_off';
    case GrievanceDecision = 'grievance_decision';
    case GrievanceLetter = 'grievance_letter';
    case DisciplinaryCase = 'disciplinary_case';
    // Organization Design & Workforce Planning
    case InstitutionMandate = 'institution_mandate';
    case OrganizationPower = 'organization_power';
    case OrganizationFunction = 'organization_function';
    case Service = 'service';
    case Process = 'process';
    case ProcessActivity = 'process_activity';
    case ProcessTask = 'process_task';
    case ReceptionDesk = 'reception_desk';
    case ReceptionVisit = 'reception_visit';
    case ReceptionAppointment = 'reception_appointment';
    case CardPrintBatch = 'card_print_batch';
    case TransportTrip = 'transport_trip';
    case TransportSettlement = 'transport_settlement';
    case TransportInvoice = 'transport_invoice';
    case CafeteriaSettlement = 'cafeteria_settlement';
    case FieldWorkRequest = 'field_work_request';
    case OrganizationalChangeRequest = 'organizational_change_request';
    case PerformanceAppeal = 'performance_appeal';
    case ManpowerPlan = 'manpower_plan';
    case LegacyBaseline = 'legacy_baseline';
    case DesignImportBatch = 'design_import_batch';
    case OrganizationDesignStudy = 'organization_design_study';
}
