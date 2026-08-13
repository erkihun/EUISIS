import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OrganizationFlatTable, { type DeletionBlockerKey, type OrgRow, type OrgRowCan } from '@/Components/OrganizationFlatTable';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import { Building2, CheckCircle, Layers, Plus, SearchIcon, Upload, XCircle } from '@/Components/Icons';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

type OrganizationRow = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    type: { code: string; name_en: string; name_am: string | null; category: string | null } | null;
    parent: { id: string; code: string; name_en: string; name_am: string | null } | null;
    created_at: string | null;
    can: OrgRowCan;
    deletion_blockers: DeletionBlockerKey[];
};

type Paginator = {
    data: OrganizationRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type HierarchyVersion = { id: string; version_name: string; status: string; approval_date: string | null };
type Filters = { search: string; type: string; status: string; category: string };

const filterInputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function OrganizationsIndex({
    organizations,
    filters,
    filterOptions,
    stats,
    publishedVersion,
    hierarchyVersions,
    can,
}: {
    organizations: Paginator;
    filters: Filters;
    filterOptions: {
        types: Array<{ code: string; name_en: string; name_am: string | null }>;
        statuses: string[];
        categories: string[];
    };
    stats: { total: number; active: number; inactive: number; types: number };
    publishedVersion: { id: string; version_name: string; approval_date: string | null } | null;
    hierarchyVersions: HierarchyVersion[];
    can: { create: boolean; manageHierarchy: boolean };
}) {
    const { locale, t } = useLocale();
    const { can: hasPermission } = useCan();
    const [values, setValues] = useState(filters);

    const rows: OrgRow[] = organizations.data.map((organization) => ({
        ...organization,
        parent_label: organization.parent
            ? `${organization.parent.code} — ${localizedName(organization.parent.name_en, organization.parent.name_am, locale)}`
            : null,
    }));

    function navigate(next: Partial<Filters> & { page?: number }) {
        router.get(
            route('organizations.index'),
            { ...values, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();
        navigate({ page: 1 });
    }

    function resetFilters() {
        const empty = { search: '', type: '', status: '', category: '' };
        setValues(empty);
        router.get(route('organizations.index'), empty, { preserveScroll: true, replace: true });
    }

    const filtersActive = Object.values(values).some((value) => value !== '');

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizations.title')}
                    description={publishedVersion
                        ? `${t('organizations.hierarchyVersion')}: ${publishedVersion.version_name}`
                        : t('organizations.noHierarchy')}
                    actions={
                        <>
                            {hasPermission('organizations.import') && (
                                <Link href={route('organizations.import-structure.create')} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                    <Upload className="h-4 w-4" aria-hidden="true" />
                                    {t('organizationStructureImport.title')}
                                </Link>
                            )}
                            {can.create && (
                                <Link href={route('organizations.create')} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-blue-700">
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

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label={t('organizations.kpiTotal')} value={stats.total} tone="primary" icon={<Building2 className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiActive')} value={stats.active} tone="success" icon={<CheckCircle className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiInactive')} value={stats.inactive} tone="warning" icon={<XCircle className="h-4 w-4" />} />
                <StatCard label={t('organizations.kpiTypes')} value={stats.types} tone="neutral" icon={<Layers className="h-4 w-4" />} />
            </div>

            <form onSubmit={submit} className="mt-4 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative min-w-0 flex-1">
                        <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                        <input type="search" value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder={t('organizations.searchByCodeOrName')} className={`${filterInputCls} w-full pl-9`} />
                    </div>
                    <select className={filterInputCls} value={values.type} onChange={(event) => { const type = event.target.value; setValues({ ...values, type }); navigate({ type, page: 1 }); }} aria-label={t('organizations.filterByType')}>
                        <option value="">{t('organizations.allTypes')}</option>
                        {filterOptions.types.map((type) => <option key={type.code} value={type.code}>{localizedName(type.name_en, type.name_am, locale)}</option>)}
                    </select>
                    <select className={filterInputCls} value={values.status} onChange={(event) => { const status = event.target.value; setValues({ ...values, status }); navigate({ status, page: 1 }); }} aria-label={t('organizations.filterByStatus')}>
                        <option value="">{t('organizations.allStatuses')}</option>
                        {filterOptions.statuses.map((status) => <option key={status} value={status}>{t(`common.${status}`)}</option>)}
                    </select>
                    {filterOptions.categories.length > 0 && (
                        <select className={filterInputCls} value={values.category} onChange={(event) => { const category = event.target.value; setValues({ ...values, category }); navigate({ category, page: 1 }); }} aria-label={t('organizations.category')}>
                            <option value="">{t('organizations.allCategories')}</option>
                            {filterOptions.categories.map((category) => <option key={category} value={category}>{t(`organizations.categories.${category}`)}</option>)}
                        </select>
                    )}
                    <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{t('common.search')}</button>
                    {filtersActive && <button type="button" onClick={resetFilters} className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 dark:border-slate-700 dark:text-slate-300">{t('common.reset')}</button>}
                </div>
            </form>

            <section className="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between gap-3 px-5 py-4">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('organizations.registeredOrganizations')}</h3>
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">{organizations.total}</span>
                </div>
                <OrganizationFlatTable rows={rows} emptyText={filtersActive ? t('organizations.noResultsMatchFilters') : t('organizations.noOrganizations')} />
                {organizations.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-200 px-5 py-4 text-sm dark:border-slate-800">
                        <span className="text-gray-500 dark:text-slate-400">{t('common.page')} {organizations.current_page} {t('common.of')} {organizations.last_page}</span>
                        <div className="flex gap-2">
                            <button type="button" disabled={organizations.current_page <= 1} onClick={() => navigate({ page: organizations.current_page - 1 })} className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700">{t('common.previous')}</button>
                            <button type="button" disabled={organizations.current_page >= organizations.last_page} onClick={() => navigate({ page: organizations.current_page + 1 })} className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700">{t('common.next')}</button>
                        </div>
                    </div>
                )}
            </section>

            {hierarchyVersions.length > 0 && (
                <section className="mt-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('organizations.hierarchyVersion')}</h3>
                        <Link href={route('hierarchy-versions.index')} className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-blue-600 dark:border-slate-700 dark:text-blue-400">{t('organizations.viewAllHierarchyVersions')}</Link>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-3">
                        {hierarchyVersions.map((version) => (
                            <Link key={version.id} href={route('hierarchy-versions.show', { hierarchyVersion: version.id })} className="flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-700 hover:border-blue-400 dark:border-slate-700 dark:text-slate-300">
                                <span className="font-medium">{version.version_name}</span>
                                <StatusBadge status={version.status} />
                            </Link>
                        ))}
                    </div>
                </section>
            )}
        </AuthenticatedLayout>
    );
}
