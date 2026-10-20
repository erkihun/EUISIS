import { useState, useMemo, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import OrganizationUnitTreeRow from '@/Components/organization-units/OrganizationUnitTreeRow';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnitTreeNode } from '@/types/organizationUnit';

import { TableCard, buttonClassName, cx, tableBodyClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';

export type UnitFilters = { search: string; unitType: string; status: string };

interface Props {
    units: OrganizationUnitTreeNode[];
    filters: UnitFilters;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canRestore: boolean;
    selectedOrgId: string;
    title: ReactNode;
}

function collectAllIds(nodes: OrganizationUnitTreeNode[]): string[] {
    const ids: string[] = [];
    for (const node of nodes) {
        ids.push(node.id);
        if (node.children && node.children.length > 0) {
            ids.push(...collectAllIds(node.children));
        }
    }
    return ids;
}

function matchesFilters(node: OrganizationUnitTreeNode, { search, unitType, status }: UnitFilters): boolean {
    const q = search.trim().toLowerCase();
    if (q && !(node.name_en.toLowerCase().includes(q) || (node.name_am ?? '').includes(search.trim()) || node.code.toLowerCase().includes(q))) return false;
    if (unitType && node.organization_unit_type_id !== unitType && node.unit_type !== unitType) return false;
    const deleted = Boolean(node.is_deleted);
    if (status === 'archived') return deleted || node.status === 'archived';
    if (status) return !deleted && node.status === status;
    return !deleted || node.status === 'archived';
}

/** Keeps a unit when it matches, or when a unit under it does (its ancestors stay, so the path is visible). */
function filterTree(nodes: OrganizationUnitTreeNode[], filters: UnitFilters): OrganizationUnitTreeNode[] {
    return nodes
        .map((node) => {
            const children = filterTree(node.children ?? [], filters);
            if (matchesFilters(node, filters) || children.length > 0) return { ...node, children };
            return null;
        })
        .filter((n): n is OrganizationUnitTreeNode => n !== null);
}

function countNodes(nodes: OrganizationUnitTreeNode[]): number {
    return nodes.reduce((total, node) => total + 1 + countNodes(node.children ?? []), 0);
}

function renderRows(nodes: OrganizationUnitTreeNode[], expanded: Set<string>, onToggle: (id: string) => void, props: Omit<Props, 'units' | 'filters' | 'title'>): ReactNode[] {
    const rows: ReactNode[] = [];
    for (const node of nodes) {
        rows.push(<OrganizationUnitTreeRow key={node.id} node={node} expanded={expanded.has(node.id)} onToggle={onToggle} {...props} />);
        if (expanded.has(node.id) && node.children && node.children.length > 0) {
            rows.push(...renderRows(node.children, expanded, onToggle, props));
        }
    }
    return rows;
}

/** The selected organization's units as a tree inside the shared list frame, filtered by the page's filter panel. */
export default function OrganizationUnitTree({ units, filters, canCreate, canUpdate, canDelete, canRestore, selectedOrgId, title }: Props) {
    const { t } = useLocale();
    const allIds = useMemo(() => collectAllIds(units), [units]);
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set(allIds));

    const filtered = useMemo(() => filterTree(units, filters), [units, filters]);
    const filtering = Boolean(filters.search.trim() || filters.unitType || filters.status);
    // A filter opens every path to a match; otherwise the operator's own expansion holds.
    const effectiveExpanded = filtering ? new Set(collectAllIds(filtered)) : expanded;
    const shown = countNodes(filtered);

    function toggleNode(id: string) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }

    return (
        <TableCard
            title={title}
            description={`${shown} ${t('organizationUnits.unitCount').toLowerCase()}`}
            actions={
                <>
                    <button type="button" onClick={() => setExpanded(new Set(allIds))} className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={filtering}>
                        {t('organizationUnits.expandAll')}
                    </button>
                    <button type="button" onClick={() => setExpanded(new Set())} className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={filtering}>
                        {t('organizationUnits.collapseAll')}
                    </button>
                </>
            }
        >
            {filtered.length === 0 ? (
                <EmptyState
                    title={filtering ? t('organizationUnits.noUnitsMatchFilters') : t('organizationUnits.noOrganizationUnitsFound')}
                    action={!filtering && canCreate ? (
                        <Link href={route('organization-units.create', { organization_id: selectedOrgId })} className={buttonClassName({ variant: 'primary' })}>
                            <Plus className="h-4 w-4" aria-hidden="true" />
                            {t('organizationUnits.addOrganizationUnit')}
                        </Link>
                    ) : undefined}
                />
            ) : (
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={tableHeaderCellClassName}>{t('common.code')}</th>
                                <th className={tableHeaderCellClassName}>{t('common.name')}</th>
                                <th className={tableHeaderCellClassName}>{t('organizationUnits.unitType')}</th>
                                <th className={tableHeaderCellClassName}>{t('common.status')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('organizationUnits.childUnits')}</th>
                                <th className={tableHeaderCellClassName}>{t('common.effectiveFrom')}</th>
                                <th className={tableHeaderCellClassName}><span className="sr-only">{t('common.actions')}</span></th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {renderRows(filtered, effectiveExpanded, toggleNode, { canCreate, canUpdate, canDelete, canRestore, selectedOrgId })}
                        </tbody>
                    </table>
                </div>
            )}
        </TableCard>
    );
}
