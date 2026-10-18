import { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import OrganizationUnitTreeRow from '@/Components/organization-units/OrganizationUnitTreeRow';
import EmptyState from '@/Components/EmptyState';
import { Plus, SearchIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnitTreeNode } from '@/types/organizationUnit';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
interface Props {
    units: OrganizationUnitTreeNode[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canRestore: boolean;
    selectedOrgId: string;
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

function matchesSearch(node: OrganizationUnitTreeNode, query: string): boolean {
    const q = query.toLowerCase();
    return (
        node.name_en.toLowerCase().includes(q) ||
        (node.name_am ?? '').includes(query) ||
        node.code.toLowerCase().includes(q)
    );
}

function filterTree(nodes: OrganizationUnitTreeNode[], query: string): OrganizationUnitTreeNode[] {
    if (!query.trim()) return nodes;
    return nodes
        .map((node) => {
            const filteredChildren = filterTree(node.children ?? [], query);
            if (matchesSearch(node, query) || filteredChildren.length > 0) {
                return { ...node, children: filteredChildren };
            }
            return null;
        })
        .filter((n): n is OrganizationUnitTreeNode => n !== null);
}

function renderRows(
    nodes: OrganizationUnitTreeNode[],
    parentNumber: string,
    expanded: Set<string>,
    onToggle: (id: string) => void,
    canCreate: boolean,
    canUpdate: boolean,
    canDelete: boolean,
    canRestore: boolean,
    selectedOrgId: string,
): React.ReactNode[] {
    const rows: React.ReactNode[] = [];
    nodes.forEach((node, index) => {
        const hierarchyNumber = parentNumber ? `${parentNumber}.${index + 1}` : `${index + 1}`;
        rows.push(
            <OrganizationUnitTreeRow
                key={node.id}
                node={node}
                hierarchyNumber={hierarchyNumber}
                expanded={expanded.has(node.id)}
                onToggle={onToggle}
                canCreate={canCreate}
                canUpdate={canUpdate}
                canDelete={canDelete}
                canRestore={canRestore}
                selectedOrgId={selectedOrgId}
            />,
        );
        if (expanded.has(node.id) && node.children && node.children.length > 0) {
            rows.push(
                ...renderRows(
                    node.children,
                    hierarchyNumber,
                    expanded,
                    onToggle,
                    canCreate,
                    canUpdate,
                    canDelete,
                    canRestore,
                    selectedOrgId,
                ),
            );
        }
    });
    return rows;
}

export default function OrganizationUnitTree({
    units,
    canCreate,
    canUpdate,
    canDelete,
    canRestore,
    selectedOrgId,
}: Props) {
    const { t } = useLocale();
    const [search, setSearch] = useState('');
    const [expanded, setExpanded] = useState<Set<string>>(() => {
        const allIds = collectAllIds(units);
        return new Set(allIds);
    });

    const filteredUnits = useMemo(() => filterTree(units, search), [units, search]);

    const allIds = useMemo(() => collectAllIds(units), [units]);

    function toggleNode(id: string) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function expandAll() {
        setExpanded(new Set(allIds));
    }

    function collapseAll() {
        setExpanded(new Set());
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="relative flex-1 min-w-48">
                    <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" />
                    <input
                        type="text"
                        className={cx(inputClassName, 'w-full pl-9 pr-3')}
                        placeholder={t('organizationUnits.searchUnits')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={expandAll}
                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                    >
                        {t('organizationUnits.expandAll')}
                    </button>
                    <button
                        type="button"
                        onClick={collapseAll}
                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                    >
                        {t('organizationUnits.collapseAll')}
                    </button>
                    {canCreate && (
                        <Link
                            href={route('organization-units.create', { organization_id: selectedOrgId })}
                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            {t('organizationUnits.addOrganizationUnit')}
                        </Link>
                    )}
                </div>
            </div>

            {filteredUnits.length === 0 ? (
                <EmptyState
                    title={t('organizationUnits.noOrganizationUnitsFound')}
                    action={
                        canCreate ? (
                            <Link
                                href={route('organization-units.create', { organization_id: selectedOrgId })}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                <Plus className="h-4 w-4" />
                                {t('organizationUnits.addOrganizationUnit')}
                            </Link>
                        ) : undefined
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('common.name')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('organizationUnits.unitType')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {t('common.status')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-center')}>
                                    {t('organizationUnits.childUnits')}
                                </th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')} />
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {renderRows(
                                filteredUnits,
                                '',
                                expanded,
                                toggleNode,
                                canCreate,
                                canUpdate,
                                canDelete,
                                canRestore,
                                selectedOrgId,
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
