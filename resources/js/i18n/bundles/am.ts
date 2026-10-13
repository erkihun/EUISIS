// The Amharic dictionary as one lazily loaded chunk (see ../registry.ts).
// Keep the namespace list identical to ./en.ts; scripts/i18n-audit.mjs checks both.
import am from '@/i18n/am';
import amAuditLogs from '@/i18n/am/auditLogs';
import amBackup from '@/i18n/am/backup';
import amCalendar from '@/i18n/am/calendar';
import amCafeteria from '@/i18n/am/cafeteria';
import amCommon from '@/i18n/am/common';
import amDailyActivities from '@/i18n/am/dailyActivities';
import amFieldWork from '@/i18n/am/fieldWork';
import amPerformance from '@/i18n/am/performance';
import amAuth from '@/i18n/am/auth';
import amConfirmations from '@/i18n/am/confirmations';
import amEntitlements from '@/i18n/am/entitlements';
import amGradeLevels from '@/i18n/am/gradeLevels';
import amIdCards from '@/i18n/am/idCards';
import amNfc from '@/i18n/am/nfc';
import amIsicActivities from '@/i18n/am/isicActivities';
import amOccupations from '@/i18n/am/occupations';
import amOrganizationDesign from '@/i18n/am/organizationDesign';
import amOrganizationalChangeRequests from '@/i18n/am/organizationalChangeRequests';
import amOrganizationTypes from '@/i18n/am/organizationTypes';
import amPositionEstablishments from '@/i18n/am/positionEstablishments';
import amProviders from '@/i18n/am/providers';
import amRoles from '@/i18n/am/roles';
import amSecurity from '@/i18n/am/security';
import amCodeRules from '@/i18n/am/codeRules';
import amDashboard from '@/i18n/am/dashboard';
import amEmployees from '@/i18n/am/employees';
import amEmployeePortal from '@/i18n/am/employeePortal';
import amEntitlementRules from '@/i18n/am/entitlementRules';
import amHome from '@/i18n/am/home';
import amHierarchyVersions from '@/i18n/am/hierarchyVersions';
import amNavigation from '@/i18n/am/navigation';
import amApiManagement from '@/i18n/am/apiManagement';
import amIdChecker from '@/i18n/am/idChecker';
import amPublicSite from '@/i18n/am/publicSite';
import amOrganizations from '@/i18n/am/organizations';
import amPositions from '@/i18n/am/positions';
import amProfile from '@/i18n/am/profile';
import amProviderPortal from '@/i18n/am/providerPortal';
import amCafeteriaPolicy from '@/i18n/am/cafeteriaPolicy';
import amProviderUsers from '@/i18n/am/providerUsers';
import amRecycleBin from '@/i18n/am/recycleBin';
import amServiceTypes from '@/i18n/am/serviceTypes';
import amServiceFeedback from '@/i18n/am/serviceFeedback';
import amSettings from '@/i18n/am/settings';
import amOrganizationUnits from '@/i18n/am/organizationUnits';
import amOrganizationUnitTypes from '@/i18n/am/organizationUnitTypes';
import amOrganizationStructureImport from '@/i18n/am/organizationStructureImport';
import amPermissions from '@/i18n/am/permissions';
import amTransfers from '@/i18n/am/transfers';
import amTransport from '@/i18n/am/transport';
import amUsers from '@/i18n/am/users';
import amVacancies from '@/i18n/am/vacancies';
import amInstitutionOffices from '@/i18n/am/institutionOffices';
import amRelationships from '@/i18n/am/relationships';
import amGrievances from '@/i18n/am/grievances';
import amGrievancePortal from '@/i18n/am/grievancePortal';
import amGrievanceCases from '@/i18n/am/grievanceCases';
import amGrievanceWork from '@/i18n/am/grievanceWork';
import amGrievanceAdmin from '@/i18n/am/grievanceAdmin';
import amCourtCases from '@/i18n/am/courtCases';
import amDiscipline from '@/i18n/am/discipline';
import amWorkStandards from '@/i18n/am/workStandards';
import amAssessments from '@/i18n/am/assessments';
import amAssessmentOversight from '@/i18n/am/assessmentOversight';
import amAssessmentWorkspace from '@/i18n/am/assessmentWorkspace';
import type { TranslationTree } from '@/i18n/registry';

const tree: TranslationTree = {
    ...(am as TranslationTree),
    auditLogs: amAuditLogs,
    calendar: amCalendar,
    common: { ...(((am as { common?: TranslationTree }).common) ?? {}), ...amCommon },
    nav: { ...(((am as { nav?: TranslationTree }).nav) ?? {}), ...amNavigation },
    dashboard: { ...(((am as { dashboard?: TranslationTree }).dashboard) ?? {}), ...amDashboard },
    employees: { ...(((am as { employees?: TranslationTree }).employees) ?? {}), ...amEmployees },
    employeePortal: amEmployeePortal,
    fieldWork: amFieldWork,
    dailyActivities: amDailyActivities,
    performance: amPerformance,
    entitlements: amEntitlements,
    gradeLevels: amGradeLevels,
    idCards: { ...(((am as { idCards?: TranslationTree }).idCards) ?? {}), ...amIdCards },
    nfc: amNfc,
    isicActivities: amIsicActivities,
    occupations: amOccupations,
    organizationDesign: amOrganizationDesign,
    apiManagement: amApiManagement,
    idChecker: amIdChecker,
    publicSite: amPublicSite,
    organizations: { ...(((am as { organizations?: TranslationTree }).organizations) ?? {}), ...amOrganizations },
    organizationTypes: amOrganizationTypes,
    organizationalChangeRequests: amOrganizationalChangeRequests,
    organizationUnits: amOrganizationUnits,
    organizationUnitTypes: amOrganizationUnitTypes,
    organizationStructureImport: amOrganizationStructureImport,
    hierarchyVersions: amHierarchyVersions,
    permissions: amPermissions,
    positionEstablishments: amPositionEstablishments,
    providers: amProviders,
    roles: { ...(((am as { roles?: TranslationTree }).roles) ?? {}), ...amRoles },
    transfers: amTransfers,
    transport: amTransport,
    positions: amPositions,
    profile: amProfile,
    providerPortal: amProviderPortal,
    cafeteriaPolicy: amCafeteriaPolicy,
    providerUsers: amProviderUsers,
    recycleBin: amRecycleBin,
    backup: amBackup,
    serviceTypes: amServiceTypes,
    serviceFeedback: amServiceFeedback,
    entitlementRules: amEntitlementRules,
    cafeteria: { ...(((am as { cafeteria?: TranslationTree }).cafeteria) ?? {}), ...amCafeteria },
    codeRules: amCodeRules,
    settings: amSettings,
    home: amHome,
    users: { ...(((am as { users?: TranslationTree }).users) ?? {}), ...amUsers },
    vacancies: amVacancies,
    institutionOffices: amInstitutionOffices,
    relationships: amRelationships,
    grievances: amGrievances,
    grievancePortal: amGrievancePortal,
    grievanceCases: amGrievanceCases,
    grievanceWork: amGrievanceWork,
    grievanceAdmin: amGrievanceAdmin,
    courtCases: amCourtCases,
    discipline: amDiscipline,
    workStandards: amWorkStandards,
    assessments: amAssessments,
    assessmentOversight: amAssessmentOversight,
    assessmentWorkspace: amAssessmentWorkspace,
    confirmations: amConfirmations,
    auth: amAuth,
    security: amSecurity,
};

export default tree;
