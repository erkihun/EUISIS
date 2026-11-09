import { Link } from '@inertiajs/react';
import { useState, type HTMLAttributes } from 'react';
import StatusBadge from '@/Components/StatusBadge';
import OrganizationActionsMenu from '@/Components/OrganizationActionsMenu';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { ChevronDown, ChevronUp, ChevronUpDown, GripVertical } from '@/Components/Icons';
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

export type OrgSortColumn = 'manual' | 'code' | 'name' | 'type' | 'category' | 'parent' | 'status' | 'created_at';
export type OrgSort = { column: OrgSortColumn; direction: 'asc' | 'desc' };

export default function OrganizationFlatTable({
    rows,
    emptyText,
    sort,
    onSort,
    onMove,
}: {
    rows: OrgRow[];
    emptyText: string;
    sort: OrgSort;
    onSort: (column: OrgSortColumn) => void;
    /** Given while arranging: rows drag and step to a new place, and links, menus and sorting step aside. */
    onMove?: (from: number, to: number) => void;
}) {
    const { t, locale } = useLocale();
    const arranging = onMove !== undefined;
    const [dragFrom, setDragFrom] = useState<number | null>(null);
    const [dragOver, setDragOver] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');

    function move(from: number, to: number) {
        if (!onMove || from === to || to < 0 || to >= rows.length) return;
        onMove(from, to);
        setAnnouncement(
            t('organizations.movedTo')
                .replace(':name', localizedName(rows[from].name_en, rows[from].name_am, locale))
                .replace(':position', String(to + 1)),
        );
    }

    function endDrag() {
        setDragFrom(null);
        setDragOver(null);
    }

    function dragProps(index: number): HTMLAttributes<HTMLTableRowElement> & { draggable?: boolean } {
        if (!arranging) return {};

        return {
            draggable: true,
            onDragStart: (event) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(index));
                setDragFrom(index);
            },
            onDragOver: (event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setDragOver(index);
            },
            onDrop: (event) => {
                event.preventDefault();
                if (dragFrom !== null) move(dragFrom, index);
                endDrag();
            },
            onDragEnd: endDrag,
        };
    }

    function dropMarker(index: number): string {
        if (dragFrom === null || dragOver !== index || dragFrom === index) return '';

        return dragFrom < index
            ? 'shadow-[inset_0_-2px_0_0_var(--app-primary)]'
            : 'shadow-[inset_0_2px_0_0_var(--app-primary)]';
    }

    function categoryLabel(category?: string | null): string {
        if (!category) return '\u2014';
        return CATEGORY_KEYS.includes(category) ? t(`organizations.categories.${category}`) : t('organizations.categories.other');
    }

    const headers: { label: string; column?: OrgSortColumn }[] = [
        ...(arranging ? [{ label: t('organizations.orderColumn') }] : []),
        { label: t('common.code'), column: 'code' },
        { label: t('common.name'), column: 'name' },
        { label: t('organizations.organizationType'), column: 'type' },
        { label: t('organizations.category'), column: 'category' },
        { label: t('organizations.parentStructure'), column: 'parent' },
        { label: t('common.status'), column: 'status' },
        { label: t('common.createdAt'), column: 'created_at' },
        ...(arranging ? [] : [{ label: '' }]),
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
            <p className="sr-only" aria-live="polite">{announcement}</p>
            <table className={cx(tableClassName, 'min-w-full text-left')}>
                <thead className={tableHeadClassName}>
                    <tr>
                        {headers.map(({ label, column }, i) => {
                            const isSorted = !arranging && column !== undefined && sort.column === column;

                            return (
                                <th
                                    key={i}
                                    scope="col"
                                    aria-sort={isSorted ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                    className={cx(tableHeaderCellClassName, 'text-left whitespace-nowrap')}
                                >
                                    {column && !arranging ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(column)}
                                            title={t('employees.sortByColumn').replace(':column', label)}
                                            className="inline-flex items-center gap-1 rounded-control font-semibold hover:text-(--app-foreground)"
                                        >
                                            {label}
                                            {isSorted ? (
                                                sort.direction === 'asc'
                                                    ? <ChevronUp className="h-3 w-3" aria-hidden="true" />
                                                    : <ChevronDown className="h-3 w-3" aria-hidden="true" />
                                            ) : (
                                                <ChevronUpDown className="h-3 w-3 text-(--app-muted-foreground)" aria-hidden="true" />
                                            )}
                                        </button>
                                    ) : label}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody className={tableBodyClassName}>
                    {rows.map((org, index) => (
                        <tr
                            key={org.id}
                            className={cx(
                                tableRowClassName,
                                arranging && 'cursor-grab active:cursor-grabbing',
                                dragFrom === index && 'opacity-50',
                                dropMarker(index),
                            )}
                            {...dragProps(index)}
                        >
                            {arranging && (
                                <td className={cx(tableCellClassName, 'whitespace-nowrap')}>
                                    <div className="flex items-center gap-1">
                                        <GripVertical className="h-4 w-4 text-(--app-muted-foreground)" aria-hidden="true" />
                                        <span className="w-6 text-center text-xs tabular-nums text-(--app-muted-foreground)">{index + 1}</span>
                                        {([
                                            { to: index - 1, label: t('organizations.moveUp'), Icon: ChevronUp },
                                            { to: index + 1, label: t('organizations.moveDown'), Icon: ChevronDown },
                                        ]).map(({ to, label, Icon }) => (
                                            <button
                                                key={label}
                                                type="button"
                                                disabled={to < 0 || to >= rows.length}
                                                onClick={() => move(index, to)}
                                                aria-label={`${label}: ${localizedName(org.name_en, org.name_am, locale)}`}
                                                title={label}
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-control text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground) disabled:cursor-not-allowed disabled:opacity-30"
                                            >
                                                <Icon className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        ))}
                                    </div>
                                </td>
                            )}
                            <td className={tableCellClassName}>
                                {arranging ? (
                                    <span className="font-medium">{org.code}</span>
                                ) : (
                                    <Link
                                        href={route('organizations.show', org.id)}
                                        className="font-medium text-primary hover:text-primary-hover dark:text-primary dark:hover:text-primary-hover"
                                    >
                                        {org.code}
                                    </Link>
                                )}
                            </td>
                            <td className={tableCellClassName}>{localizedName(org.name_en, org.name_am, locale)}</td>
                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                {org.type ? localizedName(org.type.name_en, org.type.name_am, locale) : '\u2014'}
                            </td>
                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                {categoryLabel(org.type?.category)}
                            </td>
                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                {org.parent_label ?? '\u2014'}
                            </td>
                            <td className={tableCellClassName}>
                                <StatusBadge status={org.status} label={t(`common.${org.status}`)} />
                            </td>
                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                <LocalizedDateDisplay value={org.created_at} />
                            </td>
                            {!arranging && (
                                <td className={cx(tableCellClassName, 'text-right')}>
                                    <OrganizationActionsMenu
                                        organizationId={org.id}
                                        can={org.can}
                                        deletionBlockers={org.deletion_blockers}
                                    />
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
