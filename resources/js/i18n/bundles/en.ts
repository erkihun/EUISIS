// The English dictionary as one lazily loaded chunk (see ../registry.ts).
// Keep the namespace list identical to ./am.ts; scripts/i18n-audit.mjs checks both.
import en from '@/i18n/en';
import enAuditLogs from '@/i18n/en/auditLogs';
import enCalendar from '@/i18n/en/calendar';
import enCafeteria from '@/i18n/en/cafeteria';
import enCommon from '@/i18n/en/common';
import enDailyActivities from '@/i18n/en/dailyActivities';
import enFieldWork from '@/i18n/en/fieldWork';
import enPerformance from '@/i18n/en/performance';
import enCommunication from '@/i18n/en/communication';
import enAuth from '@/i18n/en/auth';
import enConfirmations from '@/i18n/en/confirmations';
import enEntitlements from '@/i18n/en/entitlements';
import enGradeLevels from '@/i18n/en/gradeLevels';
import enIdCards from '@/i18n/en/idCards';
import enNfc from '@/i18n/en/nfc';
import enIsicActivities from '@/i18n/en/isicActivities';
import enOccupations from '@/i18n/en/occupations';
import enOrganizationDesign from '@/i18n/en/organizationDesign';
import enOrganizationalChangeRequests from '@/i18n/en/organizationalChangeRequests';
import enOrganizationTypes from '@/i18n/en/organizationTypes';
import enPositionEstablishments from '@/i18n/en/positionEstablishments';
import enProviders from '@/i18n/en/providers';
import enRoles from '@/i18n/en/roles';
import enSecurity from '@/i18n/en/security';
import enCodeRules from '@/i18n/en/codeRules';
import enDashboard from '@/i18n/en/dashboard';
import enEmployees from '@/i18n/en/employees';
import enEmployeePortal from '@/i18n/en/employeePortal';
import enEntitlementRules from '@/i18n/en/entitlementRules';
import enHome from '@/i18n/en/home';
import enHierarchyVersions from '@/i18n/en/hierarchyVersions';
import enNavigation from '@/i18n/en/navigation';
import enOrganizationUnits from '@/i18n/en/organizationUnits';
import enOrganizationUnitTypes from '@/i18n/en/organizationUnitTypes';
import enOrganizationStructureImport from '@/i18n/en/organizationStructureImport';
import enApiManagement from '@/i18n/en/apiManagement';
import enIdChecker from '@/i18n/en/idChecker';
import enPublicSite from '@/i18n/en/publicSite';
import enOrganizations from '@/i18n/en/organizations';
import enPermissions from '@/i18n/en/permissions';
import enPositions from '@/i18n/en/positions';
import enProfile from '@/i18n/en/profile';
import enProviderPortal from '@/i18n/en/providerPortal';
import enCafeteriaPolicy from '@/i18n/en/cafeteriaPolicy';
import enProviderUsers from '@/i18n/en/providerUsers';
import enRecycleBin from '@/i18n/en/recycleBin';
import enBackup from '@/i18n/en/backup';
import enServiceTypes from '@/i18n/en/serviceTypes';
import enServiceFeedback from '@/i18n/en/serviceFeedback';
import enSituationRoom from '@/i18n/en/situationRoom';
import enIntelligence from '@/i18n/en/intelligence';
import enIntelligenceKnowledge from '@/i18n/en/intelligenceKnowledge';
import enAssistant from '@/i18n/en/assistant';
import enSupport from '@/i18n/en/support';
import enPublicAssistant from '@/i18n/en/publicAssistant';
import enSettings from '@/i18n/en/settings';
import enTransfers from '@/i18n/en/transfers';
import enTransport from '@/i18n/en/transport';
import enUsers from '@/i18n/en/users';
import enVacancies from '@/i18n/en/vacancies';
import enInstitutionOffices from '@/i18n/en/institutionOffices';
import enRelationships from '@/i18n/en/relationships';
import enGrievances from '@/i18n/en/grievances';
import enGrievancePortal from '@/i18n/en/grievancePortal';
import enGrievanceCases from '@/i18n/en/grievanceCases';
import enGrievanceWork from '@/i18n/en/grievanceWork';
import enGrievanceAdmin from '@/i18n/en/grievanceAdmin';
import enCourtCases from '@/i18n/en/courtCases';
import enDiscipline from '@/i18n/en/discipline';
import enWorkStandards from '@/i18n/en/workStandards';
import enAssessments from '@/i18n/en/assessments';
import enAssessmentOversight from '@/i18n/en/assessmentOversight';
import enAssessmentWorkspace from '@/i18n/en/assessmentWorkspace';
import type { TranslationTree } from '@/i18n/registry';

const tree: TranslationTree = {
    ...en,
    auditLogs: enAuditLogs,
    calendar: enCalendar,
    common: { ...((en.common as TranslationTree | undefined) ?? {}), ...enCommon },
    nav: { ...en.nav, ...enNavigation },
    dashboard: { ...((en.dashboard as TranslationTree | undefined) ?? {}), ...enDashboard },
    employees: { ...((en.employees as TranslationTree | undefined) ?? {}), ...enEmployees },
    employeePortal: enEmployeePortal,
    fieldWork: enFieldWork,
    dailyActivities: enDailyActivities,
    performance: enPerformance,
    communication: enCommunication,
    entitlements: enEntitlements,
    gradeLevels: enGradeLevels,
    idCards: { ...((en.idCards as TranslationTree | undefined) ?? {}), ...enIdCards },
    nfc: enNfc,
    isicActivities: enIsicActivities,
    occupations: enOccupations,
    organizationDesign: enOrganizationDesign,
    apiManagement: enApiManagement,
    idChecker: enIdChecker,
    publicSite: enPublicSite,
    organizations: { ...((en.organizations as TranslationTree | undefined) ?? {}), ...enOrganizations },
    organizationTypes: enOrganizationTypes,
    organizationalChangeRequests: enOrganizationalChangeRequests,
    organizationUnits: enOrganizationUnits,
    organizationUnitTypes: enOrganizationUnitTypes,
    organizationStructureImport: enOrganizationStructureImport,
    hierarchyVersions: enHierarchyVersions,
    permissions: enPermissions,
    positionEstablishments: enPositionEstablishments,
    providers: enProviders,
    roles: { ...((en.roles as TranslationTree | undefined) ?? {}), ...enRoles },
    transfers: enTransfers,
    transport: enTransport,
    positions: enPositions,
    profile: enProfile,
    providerPortal: enProviderPortal,
    cafeteriaPolicy: enCafeteriaPolicy,
    providerUsers: enProviderUsers,
    recycleBin: enRecycleBin,
    backup: enBackup,
    serviceTypes: enServiceTypes,
    serviceFeedback: enServiceFeedback,
    situationRoom: enSituationRoom,
    intelligence: enIntelligence,
    intelligenceKnowledge: enIntelligenceKnowledge,
    assistant: enAssistant,
    support: enSupport,
    publicAssistant: enPublicAssistant,
    entitlementRules: enEntitlementRules,
    cafeteria: { ...((en.cafeteria as TranslationTree | undefined) ?? {}), ...enCafeteria },
    codeRules: enCodeRules,
    settings: enSettings,
    home: enHome,
    users: { ...((en.users as TranslationTree | undefined) ?? {}), ...enUsers },
    vacancies: enVacancies,
    institutionOffices: enInstitutionOffices,
    relationships: enRelationships,
    grievances: enGrievances,
    grievancePortal: enGrievancePortal,
    grievanceCases: enGrievanceCases,
    grievanceWork: enGrievanceWork,
    grievanceAdmin: enGrievanceAdmin,
    courtCases: enCourtCases,
    discipline: enDiscipline,
    workStandards: enWorkStandards,
    assessments: enAssessments,
    assessmentOversight: enAssessmentOversight,
    assessmentWorkspace: enAssessmentWorkspace,
    confirmations: enConfirmations,
    auth: enAuth,
    security: enSecurity,
};

export default tree;
