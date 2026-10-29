import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationUnitType } from '@/types/organizationUnit';

const colorMap: Record<OrganizationUnitType, string> = {
    department:  'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    directorate: 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    team:        'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
    unit:        'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
    office:      'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    section:     'bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-300',
};

const labelKeyMap: Record<OrganizationUnitType, string> = {
    department:  'organizationUnits.department',
    directorate: 'organizationUnits.directorate',
    team:        'organizationUnits.team',
    unit:        'organizationUnits.unit',
    office:      'organizationUnits.office',
    section:     'organizationUnits.section',
};

interface Props {
    unitType: OrganizationUnitType | string | null;
    /** The Unit Types catalog entry, which wins over the legacy fixed type when the unit has one. */
    catalog?: { code: string; name_en: string; name_am: string | null } | null;
    className?: string;
}

export default function OrganizationUnitTypeBadge({ unitType, catalog, className = '' }: Props) {
    const { t, locale } = useLocale();
    const key = (catalog?.code ?? unitType ?? '').toLowerCase();
    const colorClass = colorMap[key as OrganizationUnitType] ?? 'bg-(--app-surface-muted) text-(--app-muted-foreground)';
    const labelKey = labelKeyMap[key as OrganizationUnitType];
    const label = catalog
        ? localizedName(catalog.name_en, catalog.name_am, locale)
        : (labelKey ? t(labelKey) : unitType);
    if (!label) {
        return null;
    }
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colorClass} ${className}`}
        >
            {label}
        </span>
    );
}
