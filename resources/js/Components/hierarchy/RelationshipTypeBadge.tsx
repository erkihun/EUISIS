import { useLocale } from '@/hooks/useLocale';

function badgeClass(type: string | null): string {
    switch (type) {
        case 'reports_to':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-200';
        case 'geographically_under':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200';
        case 'service_scope':
            return 'bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-200';
        case 'oversight':
            return 'bg-violet-100 text-violet-700 dark:bg-violet-950/40 dark:text-violet-200';
        default:
            return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
    }
}

export function relationshipLabel(
    type: string | null,
    t: (key: string) => string,
): string {
    switch (type) {
        case 'reports_to':
            return t('hierarchyVersions.relationshipTypes.reportsTo');
        case 'geographically_under':
            return t('hierarchyVersions.relationshipTypes.geographicallyUnder');
        case 'service_scope':
            return t('hierarchyVersions.relationshipTypes.serviceScope');
        case 'oversight':
            return t('hierarchyVersions.relationshipTypes.oversight');
        default:
            return t('hierarchyVersions.rootOrganization');
    }
}

export default function RelationshipTypeBadge({ type }: { type: string | null }) {
    const { t } = useLocale();

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${badgeClass(type)}`}>
            {relationshipLabel(type, t)}
        </span>
    );
}
