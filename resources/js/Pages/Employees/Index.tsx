import { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AlertTriangle, Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { localizedName } from '@/utils/localizedName';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import type { OrganizationSummary } from '@/types/organizationUnit';
import type { ScopedOrganization } from '@/Components/organization-structure/ScopedOrganizationStructure';

const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'probation', 'daily_labor', 'intern', 'other'] as const;
const SYSTEM_STATUSES = ['draft', 'active', 'suspended', 'transferred', 'retired', 'terminated', 'deceased'] as const;

type Option = {
    id: string;
    code?: string | null;
    name_en?: string;
    name_am?: string | null;
    title_en?: string;
    title_am?: string | null;
    job_position_code?: string | null;
    organization_id?: string | null;
    organization_unit_id?: string | null;
};

type PositionOption = Option & {
    occupancy_status?: 'vacant' | 'occupied';
};

type EmployeeRow = {
    id: string;
    employee_number: string;
    full_name: string;
    /* Sent so `localization.employee_name_display` can shorten the name. */
    first_name?: string | null;
    last_name?: string | null;
    phone: string | null;
    email: string | null;
    photo_url: string | null;
    status: string;
    employment_type: string | null;
    duplicate_flags_count?: number;
    current_assignment?: {
        assignment_status?: string | null;
        organization?: { id?: string; name_en: string; name_am?: string | null } | null;
        organization_unit?: { id?: string; code: string | null; name_en: string; name_am?: string | null } | null;
        position?: { id?: string; job_position_code?: string | null; title_en: string; title_am?: string | null } | null;
    } | null;
};

type EmployeesPagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Filters = {
    search?: string;
    status?: string;
    organization_id?: string;
    organization_unit_id?: string;
    position_id?: string;
    employment_type?: string;
};

interface Props {
    organizationStructure: ScopedOrganization[];
    isOrganizationScoped: boolean;
    selectedOrganization: OrganizationSummary | null;
    selectedPosition: PositionOption | null;
    organizations: Option[];
    organizationUnits: Option[];
    positions: Option[];
    showOrganizationColumn: boolean;
    employees: EmployeeRow[];
    employees_pagination?: EmployeesPagination;
    filters: Filters;
    can: { create: boolean };
}

export default function EmployeesIndex({
    isOrganizationScoped,
    selectedOrganization,
    selectedPosition,
    organizations,
    organizationUnits,
    positions,
    showOrganizationColumn,
    employees,
    employees_pagination,
    filters,
    can,
}: Props) {
    const { t, locale } = useLocale();
    /* Localization settings govern how organization and employee names read. */
    const { organizationName, employeeName } = useDisplayFormat();

    const filterForm = useForm({
        search: filters.search ?? '',
        organization_id: filters.organization_id ?? selectedOrganization?.id ?? '',
        organization_unit_id: filters.organization_unit_id ?? '',
        position_id: filters.position_id ?? selectedPosition?.id ?? '',
        employment_type: filters.employment_type ?? '',
        status: filters.status ?? '',
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900';

    const selectedPositionIsOccupied = selectedPosition?.occupancy_status === 'occupied';

    const createParams = new URLSearchParams();
    if (filterForm.data.organization_id) createParams.set('organization_id', filterForm.data.organization_id);
    if (filterForm.data.organization_unit_id) createParams.set('organization_unit_id', filterForm.data.organization_unit_id);
    if (filterForm.data.position_id) createParams.set('position_id', filterForm.data.position_id);
    const createHref = `${route('employees.create')}${createParams.toString() ? `?${createParams.toString()}` : ''}`;

    function statusLabel(status: string): string | undefined {
        const translated = t(`employees.${status}`);
        return translated === `employees.${status}` ? undefined : translated;
    }

    function submitFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('employees.index'), filterForm.data, { preserveState: true, preserveScroll: true });
    }

    function updateFilter(key: 'organization_id' | 'organization_unit_id' | 'position_id', value: string) {
        if (key === 'organization_id') {
            filterForm.setData({
                ...filterForm.data,
                organization_id: value,
                organization_unit_id: '',
                position_id: '',
            });
            return;
        }

        if (key === 'organization_unit_id') {
            filterForm.setData({
                ...filterForm.data,
                organization_unit_id: value,
                position_id: '',
            });
            return;
        }

        filterForm.setData(key, value);
    }

    function goToPage(page: number) {
        router.get(route('employees.index'), { ...filters, page }, { preserveState: true, preserveScroll: true });
    }

    /* Clears every filter in one action rather than resetting six controls. */
    function resetFilters() {
        router.get(route('employees.index'), {}, { preserveState: false, preserveScroll: true });
    }

    const hasActiveFilters = Object.values(filterForm.data).some((value) => value !== '');

    const tableHeadings = [
        t('employees.employeeNumber'),
        t('employees.columnName'),
        ...(showOrganizationColumn ? [t('employees.columnOrganization')] : []),
        t('employees.organizationUnit'),
        t('employees.columnPosition'),
        t('employees.employmentTypeStatus'),
        t('employees.systemStatus'),
        t('employees.columnFlags'),
        '',
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('employees.title')}
                    actions={
                        can.create ? (
                            selectedPositionIsOccupied ? (
                                <button
                                    type="button"
                                    onClick={() => toast.error(t('employees.positionOccupiedCannotCreate'))}
                                    title={t('employees.positionOccupiedCannotCreate')}
                                    aria-disabled="true"
                                    className="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-gray-300 px-3 py-2 text-sm font-medium text-gray-600 dark:bg-slate-700 dark:text-slate-400"
                                >
                                    <Plus className="h-4 w-4" />
                                    {t('employees.addNewEmployee')}
                                </button>
                            ) : (
                                <Link
                                    href={createHref}
                                    className="inline-flex items-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    <Plus className="h-4 w-4" />
                                    {t('employees.addNewEmployee')}
                                </Link>
                            )
                        ) : null
                    }
                />
            }
        >
            <Head title={t('employees.title')} />

            <div className="space-y-4">
                <section className="rounded-lg border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form className="grid gap-3 md:grid-cols-2 xl:grid-cols-6" onSubmit={submitFilters}>
                        {/*
                          * Each control is labelled for assistive technology.
                          * The visible affordance is the "All …" first option,
                          * which is why no <label> is drawn — but a screen
                          * reader previously reached six unnamed selects.
                          */}
                        <input
                            className={inputCls}
                            aria-label={t('employees.searchPlaceholder')}
                            placeholder={t('employees.searchPlaceholder')}
                            value={filterForm.data.search}
                            onChange={(e) => filterForm.setData('search', e.target.value)}
                        />

                        {!isOrganizationScoped && (
                            <select
                                className={inputCls}
                                aria-label={t('employees.allOrganizations')}
                                value={filterForm.data.organization_id}
                                onChange={(e) => updateFilter('organization_id', e.target.value)}
                            >
                                <option value="">{t('employees.allOrganizations')}</option>
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>
                                        {organizationName(organization.name_en, organization.name_am)}
                                    </option>
                                ))}
                            </select>
                        )}

                        <select
                            className={inputCls}
                            aria-label={t('employees.allOrganizationUnits')}
                            value={filterForm.data.organization_unit_id}
                            onChange={(e) => updateFilter('organization_unit_id', e.target.value)}
                        >
                            <option value="">{t('employees.allOrganizationUnits')}</option>
                            {organizationUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.code ? `${unit.code} - ` : ''}
                                    {localizedName(unit.name_en ?? '', unit.name_am, locale)}
                                </option>
                            ))}
                        </select>

                        <select
                            className={inputCls}
                            aria-label={t('employees.allPositions')}
                            value={filterForm.data.position_id}
                            onChange={(e) => updateFilter('position_id', e.target.value)}
                        >
                            <option value="">{t('employees.allPositions')}</option>
                            {positions.map((position) => (
                                <option key={position.id} value={position.id}>
                                    {position.job_position_code ? `${position.job_position_code} - ` : ''}
                                    {localizedName(position.title_en ?? '', position.title_am, locale)}
                                </option>
                            ))}
                        </select>

                        <select
                            className={inputCls}
                            aria-label={t('employees.allEmploymentTypes')}
                            value={filterForm.data.employment_type}
                            onChange={(e) => filterForm.setData('employment_type', e.target.value)}
                        >
                            <option value="">{t('employees.allEmploymentTypes')}</option>
                            {EMPLOYMENT_TYPES.map((type) => (
                                <option key={type} value={type}>
                                    {t(`employees.employmentType_${type}`)}
                                </option>
                            ))}
                        </select>

                        <div className="flex gap-2">
                            <select
                                className={inputCls}
                                aria-label={t('employees.allStatuses')}
                                value={filterForm.data.status}
                                onChange={(e) => filterForm.setData('status', e.target.value)}
                            >
                                <option value="">{t('employees.allStatuses')}</option>
                                {SYSTEM_STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {t(`employees.${status}`)}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="submit"
                                className="shrink-0 rounded-control bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
                            >
                                {t('common.filter')}
                            </button>
                            {/* Only offered when there is something to clear. */}
                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="shrink-0 rounded-control px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                                >
                                    {t('common.reset')}
                                </button>
                            )}
                        </div>
                    </form>
                </section>

                <section className="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {employees.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('employees.noEmployeesFound')} description={t('employees.searchFiltersHint')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        {tableHeadings.map((heading, index) => (
                                            <th
                                                key={`${heading}-${index}`}
                                                className="px-4 py-2.5 text-xs font-semibold text-gray-600 dark:text-slate-400"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {employees.map((employee) => {
                                        const assignment = employee.current_assignment;
                                        const contact = employee.phone ?? employee.email ?? t('employees.notAvailable');

                                        return (
                                            <tr key={employee.id} className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200">
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <Link
                                                        href={route('employees.show', employee.id)}
                                                        className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                    >
                                                        {employee.employee_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex min-w-[14rem] items-center gap-3">
                                                        {employee.photo_url ? (
                                                            <img src={employee.photo_url} alt="" className="h-9 w-8 rounded-control object-cover" />
                                                        ) : (
                                                            <span className="flex h-9 w-8 items-center justify-center rounded-control bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                                {employeeName(employee).charAt(0).toUpperCase()}
                                                            </span>
                                                        )}
                                                        <div className="min-w-0">
                                                            <p className="truncate font-medium text-gray-900 dark:text-slate-100">{employeeName(employee)}</p>
                                                            <p className="truncate text-xs text-gray-400 dark:text-slate-500">{contact}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                {showOrganizationColumn && (
                                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                        {assignment?.organization
                                                            ? organizationName(assignment.organization.name_en, assignment.organization.name_am)
                                                            : t('common.unassigned')}
                                                    </td>
                                                )}
                                                <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                    {assignment?.organization_unit ? (
                                                        <>
                                                            {assignment.organization_unit.code ? `${assignment.organization_unit.code} - ` : ''}
                                                            {localizedName(assignment.organization_unit.name_en, assignment.organization_unit.name_am, locale)}
                                                        </>
                                                    ) : (
                                                        t('common.unassigned')
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                    {assignment?.position ? (
                                                        <>
                                                            {assignment.position.job_position_code ? `${assignment.position.job_position_code} - ` : ''}
                                                            {localizedName(assignment.position.title_en, assignment.position.title_am, locale)}
                                                        </>
                                                    ) : (
                                                        t('employees.notAvailable')
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="space-y-1">
                                                        <p className="text-gray-700 dark:text-slate-200">
                                                            {employee.employment_type
                                                                ? t(`employees.employmentType_${employee.employment_type}`)
                                                                : t('employees.notAvailable')}
                                                        </p>
                                                        <p className="text-xs text-gray-400 dark:text-slate-500">
                                                            {assignment?.assignment_status ? statusLabel(assignment.assignment_status) ?? assignment.assignment_status : t('employees.active')}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {(employee.duplicate_flags_count ?? 0) > 0 ? (
                                                        <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                                            <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
                                                            {employee.duplicate_flags_count}
                                                        </span>
                                                    ) : (
                                                        <span className="text-gray-400 dark:text-slate-500">{t('employees.notAvailable')}</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-3">
                                                        <Link href={route('employees.show', employee.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                            {t('common.view')}
                                                        </Link>
                                                        <Link href={route('employees.edit', employee.id)} className="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                                                            {t('employees.editEmployee')}
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {employees_pagination && employees_pagination.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                {t('common.page')} {employees_pagination.current_page} / {employees_pagination.last_page}
                                {' - '}
                                {employees_pagination.total} {t('common.results')}
                            </p>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={employees_pagination.current_page <= 1}
                                    onClick={() => goToPage(employees_pagination.current_page - 1)}
                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 dark:hover:border-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300"
                                >
                                    {t('common.previous')}
                                </button>
                                <button
                                    type="button"
                                    disabled={employees_pagination.current_page >= employees_pagination.last_page}
                                    onClick={() => goToPage(employees_pagination.current_page + 1)}
                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 dark:hover:border-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300"
                                >
                                    {t('common.next')}
                                </button>
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
