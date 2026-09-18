import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import {
    employeeDisplayName,
    formatNumber,
    organizationDisplayName,
    type EmployeeNameDisplay,
    type EmployeeNameParts,
    type NumberFormat,
    type OrganizationNameDisplay,
} from '@/utils/displayFormat';

/**
 * Binds the Localization settings to the formatting helpers.
 *
 * A screen calls `organizationName(...)` instead of `localizedName(...)` and
 * the administrator's choice applies; there is no per-page setting lookup to
 * forget. `localizedName` remains for entities the settings do not govern,
 * such as position titles and unit names.
 */
export function useDisplayFormat() {
    const { locale } = useLocale();
    const { getString } = useSystemSettings();

    const orgMode = getString('localization.organization_name_display', 'english') as OrganizationNameDisplay;
    const employeeMode = getString('localization.employee_name_display', 'full_name') as EmployeeNameDisplay;
    const numberMode = getString('localization.number_format', '1,234.56') as NumberFormat;

    return {
        organizationName: (nameEn: string | null | undefined, nameAm: string | null | undefined): string =>
            organizationDisplayName(nameEn, nameAm, locale, orgMode),

        employeeName: (employee: EmployeeNameParts): string => employeeDisplayName(employee, employeeMode),

        number: (value: number | string | null | undefined): string => formatNumber(value, numberMode),
    };
}
