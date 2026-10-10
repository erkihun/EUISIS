import { Link } from '@inertiajs/react';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationActionsMenu from '@/Components/OrganizationActionsMenu';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
        if (!category) return '\u2014';
        return CATEGORY_KEYS.includes(category) ? t(`organizations.categories.${category}`) : t('organizations.categories.other');
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
            <table className={cx(tableClassName, 'min-w-full text-left')}>
                <thead className={tableHeadClassName}>
                    <tr>
                        {headers.map((h, i) => (
                            <th
                                key={i}
                                className={cx(tableHeaderCellClassName, 'text-left whitespace-nowrap')}
                            >
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className={tableBodyClassName}>
                    {rows.map((org) => (
                        <tr
                            key={org.id}
                            className={tableRowClassName}
                        >
                            <td className={tableCellClassName}>
                                <Link
                                    href={route('organizations.show', org.id)}
                                    className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                >
                                    {org.code}
                                </Link>
                            </td>
                            <td className={tableCellClassName}>{localizedName(org.name_en, org.name_am, locale)}</td>
                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                {org.type ? localizedName(org.type.name_en, org.type.name_am, locale) : '\u2014'}
                            </td>
                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                {categoryLabel(org.type?.category)}
                            </td>
                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                {org.parent_label ?? '\u2014'}
                            </td>
                            <td className={tableCellClassName}>
                                <StatusBadge status={org.status} label={t(`common.${org.status}`)} />
                            </td>
                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                <LocalizedDateDisplay value={org.created_at} />
                            </td>
                            <td className={cx(tableCellClassName, 'text-right')}>
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
