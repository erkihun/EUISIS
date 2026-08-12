import { Link } from '@inertiajs/react';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationActionsMenu from '@/Components/OrganizationActionsMenu';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

export type DeletionBlockerKey =
    | 'usedInPublishedHierarchy'
    | 'hasChildOrganizations'
    | 'hasOrganizationUnits'
    | 'hasPositions'
    | 'hasEmployeeAssignments'
    | 'hasOtherReferences';

export type OrgRowCan = {
    update: boolean;
    delete: boolean;
    archive: boolean;
    deactivate: boolean;
    createChild: boolean;
};

export type OrgTypeRef = {
    name_en: string;
    name_am?: string | null;
    code: string;
    category?: string | null;
} | null;

export type OrgRow = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    type?: OrgTypeRef;
    created_at?: string | null;
    /** Pre-resolved "CODE — Name" of the parent/main structure, or null. */
    parent_label?: string | null;
    can: OrgRowCan;
    deletion_blockers?: DeletionBlockerKey[];
};

const CATEGORY_KEYS = ['root', 'functional', 'geographic', 'service_provider', 'independent', 'other'];

export default function OrganizationFlatTable({ rows, emptyText }: { rows: OrgRow[]; emptyText: string }) {
    const { t, locale } = useLocale();

    function categoryLabel(category?: string | null): string {
        if (!category) return '—';
        return CATEGORY_KEYS.includes(category) ? t(`organizations.categories.${category}`) : category;
    }

    const headers = [
        t('common.code'),
        t('common.name'),
        t('organizations.organizationType'),
        t('organizations.category'),
        t('organizations.parentStructure'),
        t('common.status'),
        t('common.createdAt'),
        '',
    ];

    if (rows.length === 0) {
        return (
            <div className="px-5 py-8">
                <EmptyState title={emptyText} />
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-gray-50 dark:bg-slate-950">
                    <tr>
                        {headers.map((h, i) => (
                            <th
                                key={i}
                                className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
                            >
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((org) => (
                        <tr
                            key={org.id}
                            className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                        >
                            <td className="px-4 py-2.5 pl-5">
                                <Link
                                    href={route('organizations.show', org.id)}
                                    className="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    {org.code}
                                </Link>
                            </td>
                            <td className="px-4 py-2.5">{localizedName(org.name_en, org.name_am, locale)}</td>
                            <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400">
                                {org.type ? localizedName(org.type.name_en, org.type.name_am, locale) : '—'}
                            </td>
                            <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400">
                                {categoryLabel(org.type?.category)}
                            </td>
                            <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400">
                                {org.parent_label ?? '—'}
                            </td>
                            <td className="px-4 py-2.5">
                                <StatusBadge status={org.status} label={t(`common.${org.status}`)} />
                            </td>
                            <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400">
                                <LocalizedDateDisplay value={org.created_at} />
                            </td>
                            <td className="px-4 py-2.5 pr-5 text-right">
                                <OrganizationActionsMenu
                                    organizationId={org.id}
                                    can={org.can}
                                    deletionBlockers={org.deletion_blockers}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
