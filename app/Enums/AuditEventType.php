<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditEventType: string
{
    case EmployeeCreated = 'employee_created';
    case EmployeeUpdated = 'employee_updated';
    case AssignmentChanged = 'assignment_changed';
    case TransferRequested = 'transfer_requested';
    case TransferSubmitted = 'transfer_submitted';
    case TransferCurrentOrganizationConfirmed = 'transfer_current_organization_confirmed';
    case TransferReceivingOrganizationConfirmed = 'transfer_receiving_organization_confirmed';
    case TransferApproved = 'transfer_approved';
    case TransferRejected = 'transfer_rejected';
    case TransferCancelled = 'transfer_cancelled';
    case TransferCompleted = 'transfer_completed';
    case OrganizationCreated = 'organization_created';
    case OrganizationUpdated = 'organization_updated';
    case OrganizationDeleted = 'organization_deleted';
    case OrganizationArchived = 'organization_archived';
    case OrganizationDeactivated = 'organization_deactivated';
    case OrganizationLogoUploaded = 'organization_logo_uploaded';
    case OrganizationBrandingUpdated = 'organization_branding_updated';
    case HierarchyPublished = 'hierarchy_published';
    case CardRequested = 'card_requested';
    case CardVerified = 'card_verified';
    case CardApproved = 'card_approved';
    case CardRejected = 'card_rejected';
    case CardCancelled = 'card_cancelled';
    case CardPrinted = 'card_printed';
    case CardPrintedAnytime = 'card.printed_anytime';
    case CardExportedPng = 'card.exported_png';
    case CardPreviewedSvg = 'card.previewed_svg';
    case CardExportedPngServer = 'card.exported_png_server';
    case CardIssued = 'card_issued';
    case CardActivated = 'card_activated';
    case CardLost = 'card_lost';
    case CardDamaged = 'card_damaged';
    case CardSuspended = 'card_suspended';
    case CardReplaced = 'card_replaced';
    case CardRevoked = 'card_revoked';
    case CardExpired = 'card_expired';
    case PrintBatchCreated = 'print_batch_created';
    case PrintBatchCompleted = 'print_batch_completed';
    case VerificationPerformed = 'verification_performed';
    case EntitlementGranted = 'entitlement_granted';
    case EntitlementRevoked = 'entitlement_revoked';
    case EntitlementOverridden = 'entitlement_overridden';
    case ServiceTransactionRecorded = 'service_transaction_recorded';
    case ServiceAccessBlocked = 'service_access_blocked';
    case ServiceTransactionReversed = 'service_transaction_reversed';
    case ProviderApiDenied = 'provider_api_denied';
    case ExportPerformed = 'export_performed';
    case SecurityEvent = 'security_event';
    case ScopeChanged = 'scope_changed';
    case PermissionChanged = 'permission_changed';

    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserPhotoUpdated = 'user_photo_updated';
    case UserDeactivated = 'user_deactivated';
    case UserRestored = 'user_restored';
    case ProfileUpdated = 'profile_updated';
    case ProfilePhotoUpdated = 'profile_photo_updated';
    case UserDeactivationBlockedSelf = 'user_deactivation_blocked_self';
    case UserDeactivationBlockedLastSuperAdmin = 'user_deactivation_blocked_last_super_admin';
    case UserLastSuperAdminRoleRemovalBlocked = 'user_last_super_admin_role_removal_blocked';
    case RoleCreated = 'role_created';
    case RoleUpdated = 'role_updated';
    case RoleDeleted = 'role_deleted';
    case OrganizationTypeCreated = 'organization_type_created';
    case OrganizationTypeUpdated = 'organization_type_updated';
    case OrganizationTypeArchived = 'organization_type_archived';
    case OrganizationTypeDeleted = 'organization_type_deleted';
    case OrganizationTypeRestored = 'organization_type_restored';
    case HierarchyVersionCreated = 'hierarchy_version_created';
    case HierarchyVersionUpdated = 'hierarchy_version_updated';
    case HierarchyVersionArchived = 'hierarchy_version_archived';
    case HierarchyRelationCreated = 'hierarchy_relation_created';
    case HierarchyRelationUpdated = 'hierarchy_relation_updated';
    case HierarchyRelationRemoved = 'hierarchy_relation_removed';
    case SettingUpdated = 'setting_updated';
    case PositionCreated = 'position_created';
    case PositionUpdated = 'position_updated';
    case PositionArchived = 'position_archived';
    case PositionRestored = 'position_restored';
    case ServiceTypeCreated = 'service_type_created';
    case ServiceTypeUpdated = 'service_type_updated';
    case ServiceTypeArchived = 'service_type_archived';
    case ServiceTypeRestored = 'service_type_restored';
    case EntitlementRuleCreated = 'entitlement_rule_created';
    case EntitlementRuleUpdated = 'entitlement_rule_updated';
    case EntitlementRuleArchived = 'entitlement_rule_archived';
    case EntitlementRuleRestored = 'entitlement_rule_restored';
    case SettingAssetUploaded = 'setting_asset_uploaded';
    case SettingsCacheCleared = 'settings_cache_cleared';
    case NotificationChannelTested = 'notification_channel_tested';
    case CodeRuleCreated = 'code_rule_created';
    case CodeRuleUpdated = 'code_rule_updated';
    case CodeRuleArchived = 'code_rule_archived';
    case CodeRuleRestored = 'code_rule_restored';
    case CodeGenerated = 'code_generated';
    case CodeManualOverrideUsed = 'code_manual_override_used';
    case CodeGenerationFailed = 'code_generation_failed';
    case CodeRuleSequenceReset = 'code_rule_sequence_reset';
    case RecordDeleted = 'record_deleted';
    case RecordRestored = 'record_restored';

    case OrganizationUnitCreated = 'organization_unit_created';
    case OrganizationUnitUpdated = 'organization_unit_updated';
    case OrganizationUnitArchived = 'organization_unit_archived';
    case OrganizationUnitRestored = 'organization_unit_restored';
    case OrganizationUnitStructureCopied = 'organization_unit_structure_copied';

    case OrganizationUnitTypeCreated = 'organization_unit_type_created';
    case OrganizationUnitTypeUpdated = 'organization_unit_type_updated';
    case OrganizationUnitTypeArchived = 'organization_unit_type_archived';
    case OrganizationUnitTypeRestored = 'organization_unit_type_restored';

    case UserOrganizationScopeAssigned = 'user.organization_scope.assigned';
    case UserOrganizationScopeUpdated = 'user.organization_scope.updated';
    case UserOrganizationScopeRemoved = 'user.organization_scope.removed';

    case PermissionCreated = 'permission.created';
    case PermissionUpdated = 'permission.updated';
    case PermissionDeleted = 'permission.deleted';
    case RolePermissionsUpdated = 'role.permissions_updated';

    case OccupationCreated = 'occupation_created';
    case OccupationUpdated = 'occupation_updated';
    case OccupationArchived = 'occupation_archived';
    case OccupationRestored = 'occupation_restored';

    case IsicActivityCreated = 'isic_activity_created';
    case IsicActivityUpdated = 'isic_activity_updated';
    case IsicActivityArchived = 'isic_activity_archived';
    case IsicActivityRestored = 'isic_activity_restored';

    case GradeLevelCreated = 'grade_level_created';
    case GradeLevelUpdated = 'grade_level_updated';
    case GradeLevelArchived = 'grade_level_archived';
    case GradeLevelRestored = 'grade_level_restored';

    // ── Cafeteria ──────────────────────────────────────────────────────────
    case CafeteriaProviderCreated = 'cafeteria_provider_created';
    case CafeteriaProviderUpdated = 'cafeteria_provider_updated';
    case CafeteriaProviderArchived = 'cafeteria_provider_archived';
    case CafeteriaProviderRestored = 'cafeteria_provider_restored';
    case CafeteriaProviderInstitutionAssigned = 'cafeteria_provider.institution_assigned';
    case CafeteriaProviderInstitutionChanged = 'cafeteria_provider.institution_changed';
    case CafeteriaScanRejectedWrongInstitution = 'cafeteria_scan.rejected_wrong_institution';
    case CafeteriaSubsidyRuleCreated = 'cafeteria_subsidy_rule_created';
    case CafeteriaSubsidyRuleUpdated = 'cafeteria_subsidy_rule_updated';
    case CafeteriaSubsidyRuleArchived = 'cafeteria_subsidy_rule_archived';
    case PublicHolidayCreated = 'public_holiday_created';
    case PublicHolidayUpdated = 'public_holiday_updated';
    case PublicHolidayArchived = 'public_holiday_archived';
    case CafeteriaTransactionScanned = 'cafeteria_transaction_scanned';
    case CafeteriaTransactionWeeklyUsage = 'cafeteria_transaction_weekly_usage';
    case CafeteriaTransactionExtraScan = 'cafeteria_transaction_extra_scan';
    case CafeteriaTransactionEmployeePayable = 'cafeteria_transaction_employee_payable';
    case CafeteriaTransactionWeekendRejected = 'cafeteria_transaction_weekend_rejected';
    case CafeteriaTransactionHolidayRejected = 'cafeteria_transaction_holiday_rejected';
    case CafeteriaTransactionHolidayScan = 'cafeteria_transaction_holiday_scan';
    case CafeteriaTransactionReversed = 'cafeteria_transaction_reversed';
    case CafeteriaScanProcessed = 'cafeteria_scan.processed';
    case CafeteriaScanDuplicateBlocked = 'cafeteria_scan.duplicate_blocked';
    case CafeteriaScanProviderAccessDenied = 'cafeteria_scan.provider_access_denied';
    case CafeteriaScanDaysConsumed = 'cafeteria_scan.days_consumed';
    case CafeteriaScanUpfrontUsage = 'cafeteria_scan.upfront_usage';
    case CafeteriaScanCalendarGenerated = 'cafeteria_scan.calendar_generated';
    case CafeteriaReportGenerated = 'cafeteria_report_generated';
    case CafeteriaReportExported = 'cafeteria_report_exported';
    case CafeteriaProviderTransactionsExported = 'cafeteria_provider_transactions.exported';
    case CafeteriaProviderPaymentClaimExported = 'cafeteria_provider_payment_claim.exported';
    case CafeteriaSettingsUpdated = 'cafeteria_settings_updated';
    case CafeteriaDayRuleUpdated = 'cafeteria_day_rule_updated';
    case CafeteriaSpecialDayCreated = 'cafeteria_special_day_created';
    case CafeteriaSpecialDayUpdated = 'cafeteria_special_day_updated';
    case CafeteriaSpecialDayArchived = 'cafeteria_special_day_archived';
    case CafeteriaSpecialDayRestored = 'cafeteria_special_day_restored';
    case EmployeeCafeteriaExclusionCreated = 'employee_cafeteria_exclusion_created';
    case EmployeeCafeteriaExclusionUpdated = 'employee_cafeteria_exclusion_updated';
    case EmployeeCafeteriaExclusionEnded = 'employee_cafeteria_exclusion_ended';
    case EmployeeCafeteriaExclusionArchived = 'employee_cafeteria_exclusion_archived';
    case EmployeeCafeteriaExclusionRestored = 'employee_cafeteria_exclusion_restored';

    // ── Organization Structure Import ──────────────────────────────────────
    case OrganizationStructureImportPreviewed = 'organization_structure_import_previewed';
    case OrganizationStructureImported = 'organization_structure_imported';
    case OrganizationStructureImportFailed = 'organization_structure_import_failed';

    // ── Position Establishments ────────────────────────────────────────────
    case PositionEstablishmentCreated = 'position_establishment_created';
    case PositionEstablishmentUpdated = 'position_establishment_updated';
    case PositionEstablishmentApproved = 'position_establishment_approved';
    case PositionEstablishmentArchived = 'position_establishment_archived';
    case PositionEstablishmentRestored = 'position_establishment_restored';

    // ── Vacancy Announcements ──────────────────────────────────────────────
    case VacancyAnnouncementCreated = 'vacancy_announcement_created';
    case VacancyAnnouncementUpdated = 'vacancy_announcement_updated';
    case VacancyAnnouncementPublished = 'vacancy_announcement_published';
    case VacancyAnnouncementClosed = 'vacancy_announcement_closed';
    case VacancyAnnouncementCancelled = 'vacancy_announcement_cancelled';

    // ── Vacancy Applications ───────────────────────────────────────────────
    case VacancyApplicationSubmitted = 'vacancy_application_submitted';
    case VacancyApplicationWithdrawn = 'vacancy_application_withdrawn';
    case VacancyApplicationScreened = 'vacancy_application_screened';
    case VacancyApplicationShortlisted = 'vacancy_application_shortlisted';
    case VacancyApplicationSelected = 'vacancy_application_selected';
    case VacancyApplicationRejected = 'vacancy_application_rejected';
    case VacancyTransferInitiated = 'vacancy_transfer_initiated';
    case VacancyTransferCompleted = 'vacancy_transfer_completed';

    // ── Transfer Module ───────────────────────────────────────────────────────
    case TransferSettingsUpdated = 'transfer_settings_updated';
    case TransferAnnouncementCreated = 'transfer_announcement_created';
    case TransferAnnouncementUpdated = 'transfer_announcement_updated';
    case TransferAnnouncementPublished = 'transfer_announcement_published';
    case TransferAnnouncementClosed = 'transfer_announcement_closed';
    case TransferAnnouncementCancelled = 'transfer_announcement_cancelled';
    case TransferApplicationSubmitted = 'transfer_application_submitted';
    case TransferApplicationUnderReview = 'transfer_application_under_review';
    case TransferApplicationVerified = 'transfer_application_verified';
    case TransferApplicationSelected = 'transfer_application_selected';
    case TransferApplicationRejected = 'transfer_application_rejected';
    case TransferApplicationWithdrawn = 'transfer_application_withdrawn';
    case TransferApplicationCancelled = 'transfer_application_cancelled';
    case TransferDocumentUploaded = 'transfer_document_uploaded';
    case TransferDocumentVerified = 'transfer_document_verified';
    case TransferDocumentRejected = 'transfer_document_rejected';
    case TransferReleaseApproved = 'transfer_release_approved';
    case TransferReleaseRejected = 'transfer_release_rejected';
    case TransferReceivingApproved = 'transfer_receiving_approved';
    case TransferReceivingRejected = 'transfer_receiving_rejected';
    case TransferFinalApproved = 'transfer_final_approved';
    case TransferFinalRejected = 'transfer_final_rejected';
    case TransferOverrideRequested = 'transfer_override_requested';
    case TransferOverrideApproved = 'transfer_override_approved';
    case TransferOverrideRejected = 'transfer_override_rejected';
    case TransferModuleCompleted = 'transfer_module_completed';

    // ── Institution Offices (module deprecated — use OrganizationUnit) ────
    case InstitutionOfficeModuleDeprecated = 'institution_office_module_deprecated';
    case InstitutionOfficeLegacyRouteRedirected = 'institution_office_legacy_route_redirected';
    case InstitutionOfficeMigratedToOrganizationUnit = 'institution_office_migrated_to_organization_unit';
    case OrganizationUnitCreatedAsOffice = 'organization_unit_created_as_office';
    case InstitutionOfficeCreated = 'institution_office_created';
    case InstitutionOfficeUpdated = 'institution_office_updated';
    case InstitutionOfficeMoved = 'institution_office_moved';
    case InstitutionOfficeDeleted = 'institution_office_deleted';
    case InstitutionOfficeRestored = 'institution_office_restored';
    case InstitutionOfficeStatusChanged = 'institution_office_status_changed';
    case InstitutionOfficeRelationshipCreated = 'institution_office_relationship.created';
    case InstitutionOfficeRelationshipUpdated = 'institution_office_relationship.updated';
    case InstitutionOfficeRelationshipDeleted = 'institution_office_relationship.deleted';
    case InstitutionOfficeRelationshipRestored = 'institution_office_relationship.restored';
    case OrganizationUnitRelationshipCreated = 'organization_unit_relationship.created';
    case OrganizationUnitRelationshipUpdated = 'organization_unit_relationship.updated';
    case OrganizationUnitRelationshipDeleted = 'organization_unit_relationship.deleted';
    case OrganizationUnitRelationshipRestored = 'organization_unit_relationship.restored';

    // ── MFA (TOTP) ─────────────────────────────────────────────────────────
    case MfaSetupStarted = 'mfa.setup_started';
    case MfaEnabled = 'mfa.enabled';
    case MfaChallengeSucceeded = 'mfa.challenge_succeeded';
    case MfaChallengeFailed = 'mfa.challenge_failed';
    case MfaDisabled = 'mfa.disabled';
    case MfaRecoveryCodeUsed = 'mfa.recovery_code_used';

    // Grievance Module
    case GrievanceSubmitted = 'grievance.submitted';
    case GrievanceAssigned = 'grievance.assigned';
    case GrievanceRequirementChecked = 'grievance.requirement_checked';
    case GrievanceResponseCompiled = 'grievance.response_compiled';
    case GrievanceResponseApproved = 'grievance.response_approved';
    case GrievanceResponseRejected = 'grievance.response_rejected';
    case GrievanceEscalated = 'grievance.escalated';
    case GrievanceTribunalReferred = 'grievance.tribunal_referred';
    case GrievanceDecisionLetterGenerated = 'grievance.decision_letter_generated';
    case GrievanceDecisionLetterDownloaded = 'grievance.decision_letter_downloaded';

    /*
     * Public Global ID Checker — anonymous, so these are the only record that
     * a card was looked up. Every step is logged, including failures, because
     * repeated OTP failures against one card are the signal that matters.
     */
    case PublicIdCheckScanned = 'public_id_check.scanned';
    case PublicIdCheckBlocked = 'public_id_check.blocked';
    case PublicIdCheckOtpSent = 'public_id_check.otp_sent';
    case PublicIdCheckOtpFailed = 'public_id_check.otp_failed';
    case PublicIdCheckOtpVerified = 'public_id_check.otp_verified';
    case PublicIdCheckInfoDisplayed = 'public_id_check.info_displayed';
}
