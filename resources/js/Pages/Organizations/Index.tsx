import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OrganizationFlatTable, { type DeletionBlockerKey, type OrgRow, type OrgRowCan } from '@/Components/OrganizationFlatTable';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import { Building2, CheckCircle, Layers, Plus, Upload, XCircle } from '@/Components/Icons';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';

import { FilterField, FilterPanel, Input, ListPagination, Select, TableCard, buttonClassName } from '@/Components/ui';
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
    const { organizationName } = useDisplayFormat();
    const { can: hasPermission } = useCan();
    const [values, setValues] = useState(filters);

    const rows: OrgRow[] = organizations.data.map((organization) => ({
        ...organization,
        parent_label: organization.parent
            ? `${organization.parent.code} — ${organizationName(organization.parent.name_en, organization.parent.name_am)}`
            : null,
    }));

    function navigate(next: Partial<Filters> & { page?: number }) {
        router.get(
            route('organizations.index'),
            { ...values, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submit() {
        navigate({ page: 1 });
    }

    function resetFilters() {
        const empty = { search: '', type: '', status: '', category: '' };
        setValues(empty);
        router.get(route('organizations.index'), empty, { preserveScroll: true, replace: true });
    }

    const filtersActive = Object.values(filters).some(Boolean);

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
                                <Link href={route('organizations.import-structure.create')} className={buttonClassName({ variant: 'outline' })}>
                                    <Upload className="h-4 w-4" aria-hidden="true" />
                                    {t('organizationStructureImport.title')}
                                </Link>
                            )}
                            {can.create && (
                                <Link href={route('organizations.create')} className={buttonClassName({ variant: 'primary' })}>
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

            <FilterPanel onSubmit={submit} onReset={resetFilters} hasActiveFilters={filtersActive} className="mt-4">
                <FilterField label={t('common.search')} wide>
                    <Input type="search" value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder={t('organizations.searchByCodeOrName')} />
                </FilterField>
                <FilterField label={t('organizations.organizationType')}>
                    <Select value={values.type} onChange={(event) => setValues({ ...values, type: event.target.value })}>
                        <option value="">{t('organizations.allTypes')}</option>
                        {filterOptions.types.map((type) => <option key={type.code} value={type.code}>{localizedName(type.name_en, type.name_am, locale)}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}>
                        <option value="">{t('organizations.allStatuses')}</option>
                        {filterOptions.statuses.map((status) => <option key={status} value={status}>{t(`common.${status}`)}</option>)}
                    </Select>
                </FilterField>
                {filterOptions.categories.length > 0 && (
                    <FilterField label={t('organizations.category')}>
                        <Select value={values.category} onChange={(event) => setValues({ ...values, category: event.target.value })}>
                            <option value="">{t('organizations.allCategories')}</option>
                            {filterOptions.categories.map((category) => <option key={category} value={category}>{t(`organizations.categories.${category}`)}</option>)}
                        </Select>
                    </FilterField>
                )}
            </FilterPanel>

            <TableCard title={t('organizations.registeredOrganizations')} className="mt-4">
                <OrganizationFlatTable rows={rows} emptyText={filtersActive ? t('organizations.noResultsMatchFilters') : t('organizations.noOrganizations')} />
                <ListPagination meta={organizations} onPageChange={(page) => router.get(route('organizations.index'), { ...filters, page }, { preserveState: true, preserveScroll: true, replace: true })} />
            </TableCard>

            {hierarchyVersions.length > 0 && (
                <section className="mt-4 rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-semibold text-(--app-foreground)">{t('organizations.hierarchyVersion')}</h3>
                        <Link href={route('hierarchy-versions.index')} className="rounded-lg border border-(--app-border) px-3 py-1.5 text-sm font-medium text-primary dark:text-primary">{t('organizations.viewAllHierarchyVersions')}</Link>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-3">
                        {hierarchyVersions.map((version) => (
                            <Link key={version.id} href={route('hierarchy-versions.show', { hierarchyVersion: version.id })} className="flex items-center gap-2 rounded-card border border-(--app-border) px-4 py-2.5 text-sm text-(--app-foreground) hover:border-blue-400">
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
