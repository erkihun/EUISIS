import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnitStatus } from '@/types/organizationUnit';

const colorMap: Record<OrganizationUnitStatus, string> = {
    active:   'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    draft:    'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
    inactive: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    archived: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

const labelKeyMap: Record<OrganizationUnitStatus, string> = {
    active:   'common.active',
    draft:    'common.draft',
    inactive: 'common.inactive',
    archived: 'common.archived',
};

interface Props {
    status: OrganizationUnitStatus | string;
    className?: string;
}

export default function OrganizationUnitStatusBadge({ status, className = '' }: Props) {
    const { t } = useLocale();
    const colorClass = colorMap[status as OrganizationUnitStatus] ?? 'bg-gray-100 text-gray-600';
    const labelKey = labelKeyMap[status as OrganizationUnitStatus];
    const label = labelKey ? t(labelKey) : status;
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colorClass} ${className}`}
        >
            {label}
        </span>
    );
}
