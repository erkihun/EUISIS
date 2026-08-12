import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import OrganizationTreeRow, { OrgTreeNode } from '@/Components/OrganizationTreeRow';
import OrganizationFlatTable, { OrgRow, DeletionBlockerKey, OrgRowCan } from '@/Components/OrganizationFlatTable';
import { Building2, CheckCircle, XCircle, Layers, Plus, SearchIcon, Upload } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useCan } from '@/hooks/useCan';
import { localizedName } from '@/utils/localizedName';

type HierarchyVersion = {
    id: string;
    version_name: string;
    status: string;
    approval_date: string | null;
};

type CanProps = {
    create: boolean;
    manageHierarchy: boolean;
};

type IndexStats = {
    total: number;
    active: number;
    inactive: number;
    types: number;
};

type UnassignedOrg = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    type?: { name_en: string; name_am?: string | null; code: string; category?: string | null } | null;
    created_at?: string | null;
    can: OrgRowCan;
    deletion_blockers: DeletionBlockerKey[];
};

const filterInputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function OrganizationsIndex({
    tree,
    unassigned,
    stats,
    publishedVersion,
    hierarchyVersions,
    can,
}: {
    tree: OrgTreeNode[];
    unassigned: UnassignedOrg[];
    stats: IndexStats;
    publishedVersion: { id: string; version_name: string; approval_date: string | null } | null;
    hierarchyVersions: HierarchyVersion[];
    can: CanProps;
}) {
    const { locale, t } = useLocale();
    const { can: hasPermission } = useCan();

    // The policy enforces this server-side; this only decides whether the entry
    // point is shown.
    const canImportStructure = hasPermission('organizations.import');

    // ── Tree expand/collapse state ────────────────────────────────────────
    const expandableIds = useMemo(
        () => tree.filter((node) => node.children_count > 0).map((node) => node.id),
        [tree],
    );
    const parentById = useMemo(
        () => new Map(tree.map((node) => [node.id, node.parent_id])),
        [tree],
    );
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => new Set(expandableIds));

    const visibleTree = useMemo(() => {
        return tree.filter((node) => {
            let parentId = node.parent_id;
            while (parentId !== null) {
                if (!expandedIds.has(parentId)) return false;
                parentId = parentById.get(parentId) ?? null;
            }
            return true;
        });
    }, [expandedIds, parentById, tree]);

    function toggleNode(id: string) {
        setExpandedIds((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    // ── Filter state ──────────────────────────────────────────────────────
    const [search, setSearch] = useState('');
    const [typeCode, setTypeCode] = useState('');
    const [status, setStatus] = useState('');
    const [category, setCategory] = useState('');

    const filtersActive = search.trim() !== '' || typeCode !== '' || status !== '' || category !== '';

    /** Normalized, de-duplicated rows across the tree and unassigned lists. */
    const allRows = useMemo<OrgRow[]>(() => {
        const nodeById = new Map(tree.map((n) => [n.id, n]));
        const parentLabel = (parentId: string | null): string | null => {
            if (!parentId) return null;
            const p = nodeById.get(parentId);
            return p ? `${p.code} — ${localizedName(p.name_en, p.name_am, locale)}` : null;
        };

        const treeRows: OrgRow[] = tree.map((n) => ({
            id: n.id,
            code: n.code,
            name_en: n.name_en,
            name_am: n.name_am,
            status: n.status,
            type: n.type,
            created_at: n.created_at ?? null,
            parent_label: parentLabel(n.parent_id),
            can: n.can ?? { update: false, delete: false, archive: false, deactivate: false, createChild: false },
            deletion_blockers: n.deletion_blockers,
        }));

        const unassignedRows: OrgRow[] = unassigned.map((o) => ({
            id: o.id,
            code: o.code,
            name_en: o.name_en,
            name_am: o.name_am,
            status: o.status,
            type: o.type,
            created_at: o.created_at ?? null,
            parent_label: null,
            can: o.can,
            deletion_blockers: o.deletion_blockers,
        }));

        const seen = new Set<string>();
        return [...treeRows, ...unassignedRows].filter((r) => (seen.has(r.id) ? false : seen.add(r.id)));
    }, [tree, unassigned, locale]);

    // Filter options derived from the data actually present.
    const typeOptions = useMemo(() => {
        const map = new Map<string, string>();
        allRows.forEach((r) => {
            if (r.type?.code) map.set(r.type.code, localizedName(r.type.name_en, r.type.name_am, locale));
        });
        return [...map.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [allRows, locale]);

    const statusOptions = useMemo(
        () => [...new Set(allRows.map((r) => r.status))].sort(),
        [allRows],
    );

    const categoryOptions = useMemo(
        () => [...new Set(allRows.map((r) => r.type?.category).filter((c): c is string => !!c))].sort(),
        [allRows],
    );

    const filteredRows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return allRows.filter((r) => {
            if (typeCode && r.type?.code !== typeCode) return false;
            if (status && r.status !== status) return false;
            if (category && r.type?.category !== category) return false;
            if (q) {
                const hay = `${r.code} ${r.name_en} ${r.name_am ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [allRows, search, typeCode, status, category]);

    function resetFilters() {
        setSearch('');
        setTypeCode('');
        setStatus('');
        setCategory('');
    }

    const unassignedRows = useMemo<OrgRow[]>(
        () => allRows.filter((r) => unassigned.some((u) => u.id === r.id)),
        [allRows, unassigned],
    );

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizations.title')}
                    description={
                        publishedVersion
                            ? `${t('organizations.hierarchyVersion')}: ${publishedVersion.version_name}`
                            : t('organizations.noHierarchy')
                    }
                    actions={
                        <>
                            {canImportStructure && (
                                <Link
                                    href={route('organizations.import-structure.create')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:focus-visible:ring-offset-slate-900"
                                >
                                    <Upload className="h-4 w-4" aria-hidden="true" />
                                    {t('organizationStructureImport.title')}
                                </Link>
                            )}
                            {can.create && (
                                <Link
                                    href={route('organizations.create')}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                                >
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    {t('organizations.createOrganization')}
                                </Link>
                            )}
                        </>
                    }
                />
            }
        >
            <Head title={t('organizations.title')} />

            {/* KPI summary */}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label={t('organizations.kpiTotal')} value={stats.total} tone="primary" icon={<Building2 className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiActive')} value={stats.active} tone="success" icon={<CheckCircle className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiInactive')} value={stats.inactive} tone="warning" icon={<XCircle className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiTypes')} value={stats.types} tone="neutral" icon={<Layers className="h-4 w-4" />} />
            </div>

            {/* Filter bar */}
            <div className="mt-4 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative min-w-0 flex-1">
                        <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('organizations.searchByCodeOrName')}
                            className={`${filterInputCls} w-full pl-9`}
                            aria-label={t('organizations.searchByCodeOrName')}
                        />
                    </div>
                    <select className={filterInputCls} value={typeCode} onChange={(e) => setTypeCode(e.target.value)} aria-label={t('organizations.filterByType')}>
                        <option value="">{t('organizations.allTypes')}</option>
                        {typeOptions.map(([code, label]) => (
                            <option key={code} value={code}>{label}</option>
                        ))}
                    </select>
                    <select className={filterInputCls} value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('organizations.filterByStatus')}>
                        <option value="">{t('organizations.allStatuses')}</option>
                        {statusOptions.map((s) => (
                            <option key={s} value={s}>{t(`common.${s}`)}</option>
                        ))}
                    </select>
                    {categoryOptions.length > 0 && (
                        <select className={filterInputCls} value={category} onChange={(e) => setCategory(e.target.value)} aria-label={t('organizations.category')}>
                            <option value="">{t('organizations.allCategories')}</option>
                            {categoryOptions.map((c) => (
                                <option key={c} value={c}>{t(`organizations.categories.${c}`)}</option>
                            ))}
                        </select>
                    )}
                    {filtersActive && (
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 hover:border-gray-300 hover:text-gray-800 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100"
                        >
                            {t('common.reset')}
                        </button>
                    )}
                </div>
            </div>

            {/* Filtered results (flat) OR hierarchy tree + unassigned */}
            {filtersActive ? (
                <section className="mt-4 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center gap-3 px-5 py-4">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('organizations.allOrganizations')}</h3>
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                            {filteredRows.length}
                        </span>
                    </div>
                    <OrganizationFlatTable rows={filteredRows} emptyText={t('organizations.noResultsMatchFilters')} />
                </section>
            ) : (
                <>
                    {/* Hierarchy tree */}
                    <section className="mt-4 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between px-5 py-4">
                            <div className="flex items-center gap-3">
                                <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                                    {t('organizations.registeredOrganizations')}
                                </h3>
                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                                    {tree.length}
                                </span>
                            </div>
                            {tree.length > 0 && (
                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setExpandedIds(new Set(expandableIds))}
                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-blue-500 dark:hover:text-blue-300"
                                    >
                                        {t('organizations.expandAll')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setExpandedIds(new Set())}
                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-blue-500 dark:hover:text-blue-300"
                                    >
                                        {t('organizations.collapseAll')}
                                    </button>
                                </div>
                            )}
                        </div>

                        {tree.length === 0 ? (
                            <div className="px-5 pb-5">
                                <EmptyState
                                    title={t('organizations.noOrganizations')}
                                    description={t('organizations.noHierarchy')}
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="bg-gray-50 dark:bg-slate-950">
                                        <tr>
                                            {[
                                                t('common.code'),
                                                t('common.name'),
                                                t('organizations.organizationType'),
                                                t('common.status'),
                                                t('common.createdAt'),
                                                '',
                                            ].map((h, i) => (
                                                <th
                                                    key={i}
                                                    className="whitespace-nowrap px-3 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
                                                >
                                                    {h}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visibleTree.map((node) => (
                                            <OrganizationTreeRow
                                                key={node.id}
                                                node={node}
                                                expanded={expandedIds.has(node.id)}
                                                onToggle={toggleNode}
                                                can={node.can ?? { update: false, delete: false, archive: false, deactivate: false, createChild: false }}
                                                deletionBlockers={node.deletion_blockers}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    {/* Organizations not placed in the published hierarchy */}
                    {unassignedRows.length > 0 && (
                        <section className="mt-4 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div className="flex items-center gap-3 px-5 py-4">
                                <div>
                                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                                        {t('organizations.unassignedTitle')}
                                    </h3>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                        {t('organizations.unassignedDescription')}
                                    </p>
                                </div>
                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                    {unassignedRows.length}
                                </span>
                            </div>
                            <OrganizationFlatTable rows={unassignedRows} emptyText={t('organizations.noOrganizations')} />
                        </section>
                    )}
                </>
            )}

            {/* Hierarchy versions */}
            {hierarchyVersions.length > 0 && (
                <section className="mt-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                            {t('organizations.hierarchyVersion')}
                        </h3>
                        <Link
                            href={route('hierarchy-versions.index')}
                            className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-blue-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-blue-400 dark:hover:border-blue-500 dark:hover:text-blue-300"
                        >
                            {t('organizations.viewAllHierarchyVersions')}
                        </Link>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-3">
                        {hierarchyVersions.map((v) => (
                            <Link
                                key={v.id}
                                href={route('hierarchy-versions.show', { hierarchyVersion: v.id })}
                                className="flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-700 transition-colors hover:border-blue-400 hover:text-blue-600 dark:border-slate-700 dark:text-slate-300 dark:hover:border-blue-500 dark:hover:text-blue-400"
                            >
                                <span className="font-medium">{v.version_name}</span>
                                <StatusBadge status={v.status} />
                            </Link>
                        ))}
                    </div>
                </section>
            )}
        </AuthenticatedLayout>
    );
}
