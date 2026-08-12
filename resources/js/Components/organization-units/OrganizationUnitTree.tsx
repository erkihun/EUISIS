import { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import OrganizationUnitTreeRow from '@/Components/organization-units/OrganizationUnitTreeRow';
import EmptyState from '@/Components/EmptyState';
import { Plus, SearchIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnitTreeNode } from '@/types/organizationUnit';

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
                    <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                    <input
                        type="text"
                        className="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                        placeholder={t('organizationUnits.searchUnits')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={expandAll}
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        {t('organizationUnits.expandAll')}
                    </button>
                    <button
                        type="button"
                        onClick={collapseAll}
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        {t('organizationUnits.collapseAll')}
                    </button>
                    {canCreate && (
                        <Link
                            href={route('organization-units.create', { organization_id: selectedOrgId })}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
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
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            >
                                <Plus className="h-4 w-4" />
                                {t('organizationUnits.addOrganizationUnit')}
                            </Link>
                        ) : undefined
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="w-full text-sm">
                        <thead className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.unitType')}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('common.status')}
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('organizationUnits.childUnits')}
                                </th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
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
