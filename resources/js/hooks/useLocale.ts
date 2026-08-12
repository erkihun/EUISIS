import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import { useLocaleContext } from '@/contexts/LocaleContext';
import am from '@/i18n/am';
import amAuditLogs from '@/i18n/am/auditLogs';
import amCalendar from '@/i18n/am/calendar';
import amCafeteria from '@/i18n/am/cafeteria';
import amCommon from '@/i18n/am/common';
import amAuth from '@/i18n/am/auth';
import amConfirmations from '@/i18n/am/confirmations';
import amEntitlements from '@/i18n/am/entitlements';
import amGradeLevels from '@/i18n/am/gradeLevels';
import amIdCards from '@/i18n/am/idCards';
import amIsicActivities from '@/i18n/am/isicActivities';
import amOccupations from '@/i18n/am/occupations';
import amOrganizationTypes from '@/i18n/am/organizationTypes';
import amPositionEstablishments from '@/i18n/am/positionEstablishments';
import amProviders from '@/i18n/am/providers';
import amRoles from '@/i18n/am/roles';
import amSecurity from '@/i18n/am/security';
import amCodeRules from '@/i18n/am/codeRules';
import amDashboard from '@/i18n/am/dashboard';
import amEmployees from '@/i18n/am/employees';
import amEntitlementRules from '@/i18n/am/entitlementRules';
import amHome from '@/i18n/am/home';
import amHierarchyVersions from '@/i18n/am/hierarchyVersions';
import amNavigation from '@/i18n/am/navigation';
import amOrganizations from '@/i18n/am/organizations';
import amPositions from '@/i18n/am/positions';
import amProfile from '@/i18n/am/profile';
import amProviderPortal from '@/i18n/am/providerPortal';
import amRecycleBin from '@/i18n/am/recycleBin';
import amServiceTypes from '@/i18n/am/serviceTypes';
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
import en from '@/i18n/en';
import enAuditLogs from '@/i18n/en/auditLogs';
import enCalendar from '@/i18n/en/calendar';
import enCafeteria from '@/i18n/en/cafeteria';
import enCommon from '@/i18n/en/common';
import enAuth from '@/i18n/en/auth';
import enConfirmations from '@/i18n/en/confirmations';
import enEntitlements from '@/i18n/en/entitlements';
import enGradeLevels from '@/i18n/en/gradeLevels';
import enIdCards from '@/i18n/en/idCards';
import enIsicActivities from '@/i18n/en/isicActivities';
import enOccupations from '@/i18n/en/occupations';
import enOrganizationTypes from '@/i18n/en/organizationTypes';
import enPositionEstablishments from '@/i18n/en/positionEstablishments';
import enProviders from '@/i18n/en/providers';
import enRoles from '@/i18n/en/roles';
import enSecurity from '@/i18n/en/security';
import enCodeRules from '@/i18n/en/codeRules';
import enDashboard from '@/i18n/en/dashboard';
import enEmployees from '@/i18n/en/employees';
import enEntitlementRules from '@/i18n/en/entitlementRules';
import enHome from '@/i18n/en/home';
import enHierarchyVersions from '@/i18n/en/hierarchyVersions';
import enNavigation from '@/i18n/en/navigation';
import enOrganizationUnits from '@/i18n/en/organizationUnits';
import enOrganizationUnitTypes from '@/i18n/en/organizationUnitTypes';
import enOrganizationStructureImport from '@/i18n/en/organizationStructureImport';
import enOrganizations from '@/i18n/en/organizations';
import enPermissions from '@/i18n/en/permissions';
import enPositions from '@/i18n/en/positions';
import enProfile from '@/i18n/en/profile';
import enProviderPortal from '@/i18n/en/providerPortal';
import enRecycleBin from '@/i18n/en/recycleBin';
import enServiceTypes from '@/i18n/en/serviceTypes';
import enSettings from '@/i18n/en/settings';
import enTransfers from '@/i18n/en/transfers';
import enTransport from '@/i18n/en/transport';
import enUsers from '@/i18n/en/users';
import enVacancies from '@/i18n/en/vacancies';
import enInstitutionOffices from '@/i18n/en/institutionOffices';
import enRelationships from '@/i18n/en/relationships';
import enGrievances from '@/i18n/en/grievances';
import amGrievances from '@/i18n/am/grievances';
import type { PageProps } from '@/types';

type Locale = 'en' | 'am';
type TranslationTree = Record<string, unknown>;

const translations: Record<Locale, TranslationTree> = {
    en: {
        ...en,
        auditLogs: enAuditLogs,
        calendar: enCalendar,
        common: { ...((en.common as TranslationTree | undefined) ?? {}), ...enCommon },
        nav: { ...en.nav, ...enNavigation },
        dashboard: { ...((en.dashboard as TranslationTree | undefined) ?? {}), ...enDashboard },
        employees: { ...((en.employees as TranslationTree | undefined) ?? {}), ...enEmployees },
        entitlements: enEntitlements,
        gradeLevels: enGradeLevels,
        idCards: { ...((en.idCards as TranslationTree | undefined) ?? {}), ...enIdCards },
        isicActivities: enIsicActivities,
        occupations: enOccupations,
        organizations: { ...((en.organizations as TranslationTree | undefined) ?? {}), ...enOrganizations },
        organizationTypes: enOrganizationTypes,
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
        recycleBin: enRecycleBin,
        serviceTypes: enServiceTypes,
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
        confirmations: enConfirmations,
        auth: enAuth,
        security: enSecurity,
    },
    am: {
        ...(am as TranslationTree),
        auditLogs: amAuditLogs,
        calendar: amCalendar,
        common: { ...(((am as { common?: TranslationTree }).common) ?? {}), ...amCommon },
        nav: { ...(((am as { nav?: TranslationTree }).nav) ?? {}), ...amNavigation },
        dashboard: { ...(((am as { dashboard?: TranslationTree }).dashboard) ?? {}), ...amDashboard },
        employees: { ...(((am as { employees?: TranslationTree }).employees) ?? {}), ...amEmployees },
        entitlements: amEntitlements,
        gradeLevels: amGradeLevels,
        idCards: { ...(((am as { idCards?: TranslationTree }).idCards) ?? {}), ...amIdCards },
        isicActivities: amIsicActivities,
        occupations: amOccupations,
        organizations: { ...(((am as { organizations?: TranslationTree }).organizations) ?? {}), ...amOrganizations },
        organizationTypes: amOrganizationTypes,
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
        recycleBin: amRecycleBin,
        serviceTypes: amServiceTypes,
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
        confirmations: amConfirmations,
        auth: amAuth,
        security: amSecurity,
    },
};

function getNestedValue(tree: TranslationTree, path: string): string {
    const segments = path.split('.');
    let current: unknown = tree;

    for (const segment of segments) {
        if (current === null || typeof current !== 'object') {
            return path;
        }

        current = (current as TranslationTree)[segment];
    }

    return typeof current === 'string' ? current : path;
}

export function useLocale() {
    const { locale, setLocale } = useLocaleContext();
    const page = usePage<PageProps<{ locale?: string; settings?: Record<string, unknown> }>>();
    const supportedLocales = ((page.props.settings?.['localization.supported_locales'] as string[] | undefined) ?? ['en', 'am'])
        .filter((code): code is Locale => code === 'en' || code === 'am');

    const t = useCallback(
        (key: string): string => getNestedValue(translations[locale] ?? translations.en, key),
        [locale],
    );

    const localeOptions = useMemo(
        () => supportedLocales.map((code) => ({ value: code, label: code === 'am' ? 'አማ' : 'EN' })),
        [supportedLocales],
    );

    return { locale, setLocale, t, localeOptions };
}
