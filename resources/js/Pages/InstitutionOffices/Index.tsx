import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface OrganizationOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface OfficeUnitItem {
    id: string;
    organization_id: string;
    organization: OrganizationOption | null;
    parent_unit_id: string | null;
    parent: OrganizationOption | null;
    unitType: OrganizationOption | null;
    code: string;
    name_en: string | null;
    name_am: string | null;
    status: string;
    children_count: number;
    functional_reporting: OrganizationOption | null;
}

interface Props {
    offices: PaginatedResponse<OfficeUnitItem>;
    institutions: OrganizationOption[];
    statusOptions: SelectOption[];
    filters: {
        institution_id?: string;
        status?: string;
        search?: string;
    };
    can: { create: boolean };
}

export default function InstitutionOfficesIndex({
    offices,
    institutions,
    statusOptions,
    filters,
    can,
}: Props) {
    const { t } = useLocale();

    const inputCls =
        'rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';

    function handleFilter(key: string, value: string) {
        router.get(
            route('institution-offices.index'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('institutionOffices.title')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('institution-offices.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                <Plus className="h-4 w-4" />
                                {t('institutionOffices.create')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('institutionOffices.title')} />

            <section className="mb-4 flex flex-wrap items-center gap-3">
                <input
                    type="text"
                    placeholder={t('institutionOffices.searchOffices')}
                    defaultValue={filters.search ?? ''}
                    onBlur={(event) => handleFilter('search', event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') handleFilter('search', (event.target as HTMLInputElement).value);
                    }}
                    className={inputCls}
                />

                <select
                    value={filters.institution_id ?? ''}
                    onChange={(event) => handleFilter('institution_id', event.target.value)}
                    className={inputCls}
                >
                    <option value="">{t('institutionOffices.filterByInstitution')}</option>
                    {institutions.map((institution) => (
                        <option key={institution.id} value={institution.id}>
                            {institution.name_en} ({institution.code})
                        </option>
                    ))}
                </select>

                <select
                    value={filters.status ?? ''}
                    onChange={(event) => handleFilter('status', event.target.value)}
                    className={inputCls}
                >
                    <option value="">{t('institutionOffices.filterByStatus')}</option>
                    {statusOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </section>

            <section className="rounded-lg border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('institutionOffices.unitCode'),
                                    t('institutionOffices.officeUnitName'),
                                    t('institutionOffices.structuralOrganization'),
                                    t('institutionOffices.parentOrganizationUnit'),
                                    t('institutionOffices.functionalReportingOrganization'),
                                    t('institutionOffices.status'),
                                    t('common.actions'),
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        className="px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {offices.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500"
                                    >
                                        {t('institutionOffices.noOffices')}
                                    </td>
                                </tr>
                            ) : (
                                offices.data.map((office) => (
                                    <tr
                                        key={office.id}
                                        className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">{office.code}</td>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{office.name_en ?? t('institutionOffices.notSelected')}</div>
                                            {office.name_am && (
                                                <div className="text-xs text-gray-500 dark:text-slate-400">
                                                    {office.name_am}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                                            {office.organization?.name_en ?? t('institutionOffices.notSelected')}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                                            {office.parent ? (
                                                <Link
                                                    href={route('organization-units.show', office.parent.id)}
                                                    className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {office.parent.name_en}
                                                </Link>
                                            ) : (
                                                <span className="text-gray-400 dark:text-slate-500">{t('institutionOffices.notSelected')}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                                            {office.functional_reporting?.name_en ?? t('institutionOffices.notSelected')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={office.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('organization-units.show', office.id)}
                                                    className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {t('common.view')}
                                                </Link>
                                                <Link
                                                    href={route('organization-units.edit', office.id)}
                                                    className="text-xs text-gray-600 hover:underline dark:text-slate-300"
                                                >
                                                    {t('common.edit')}
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {offices.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                        <p className="text-xs text-gray-500 dark:text-slate-400">
                            {offices.from}-{offices.to} / {offices.total}
                        </p>
                        <div className="flex gap-2">
                            {offices.prev_page_url && (
                                <Link
                                    href={offices.prev_page_url}
                                    className="rounded-md border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50 dark:border-slate-600 dark:hover:bg-slate-800"
                                >
                                    {t('common.previous')}
                                </Link>
                            )}
                            {offices.next_page_url && (
                                <Link
                                    href={offices.next_page_url}
                                    className="rounded-md border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50 dark:border-slate-600 dark:hover:bg-slate-800"
                                >
                                    {t('common.next')}
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
