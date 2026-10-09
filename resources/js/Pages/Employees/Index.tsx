import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AlertTriangle, ChevronDown, ChevronUp, ChevronUpDown, Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { localizedName } from '@/utils/localizedName';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import type { OrganizationSummary } from '@/types/organizationUnit';

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

type SortColumn = 'full_name' | 'employee_number' | 'employment_type' | 'status' | 'created_at';

type SortState = {
    column: SortColumn;
    direction: 'asc' | 'desc';
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
    sort: SortState;
    can: { create: boolean; update: boolean };
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
    sort,
    can,
}: Props) {
    const { t, locale } = useLocale();
    /* Localization settings govern how organization and employee names read. */
    const { organizationName, employeeName } = useDisplayFormat();
    const [loading, setLoading] = useState(false);

    const filterForm = useForm({
        search: filters.search ?? '',
        organization_id: filters.organization_id ?? selectedOrganization?.id ?? '',
        organization_unit_id: filters.organization_unit_id ?? '',
        position_id: filters.position_id ?? selectedPosition?.id ?? '',
        employment_type: filters.employment_type ?? '',
        status: filters.status ?? '',
    });
    useEffect(() => {
        filterForm.setData({
            search: filters.search ?? '', organization_id: filters.organization_id ?? selectedOrganization?.id ?? '',
            organization_unit_id: filters.organization_unit_id ?? '', position_id: filters.position_id ?? selectedPosition?.id ?? '',
            employment_type: filters.employment_type ?? '', status: filters.status ?? '',
        });
    }, [filters, selectedOrganization?.id, selectedPosition?.id]);

    /*
     * Search applies as you type. The registry is the one filter people reach
     * for constantly, and requiring a round trip to the Filter button for every
     * query made a 50-row page feel like a form submission. The other five
     * controls still submit explicitly — they are cheap to set exactly once.
     */
    useEffect(() => {
        if (filterForm.data.search === (filters.search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(route('employees.index'), withSort(filterForm.data), visitOptions);
        }, 350);

        return () => window.clearTimeout(timer);
    }, [filterForm.data.search, filters.search]);

    const visitOptions = {
        preserveState: true, preserveScroll: true,
        onStart: () => setLoading(true), onFinish: () => setLoading(false),
        onError: () => toast.error(t('employees.registryLoadError')),
    };

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900';

    const selectedPositionIsOccupied = selectedPosition?.occupancy_status === 'occupied';

    const createParams = new URLSearchParams();
    if (filters.organization_id) createParams.set('organization_id', filters.organization_id);
    if (filters.organization_unit_id) createParams.set('organization_unit_id', filters.organization_unit_id);
    if (filters.position_id) createParams.set('position_id', filters.position_id);
    const createHref = `${route('employees.create')}${createParams.toString() ? `?${createParams.toString()}` : ''}`;

    function statusLabel(status: string): string | undefined {
        const translated = t(`employees.${status}`);
        return translated === `employees.${status}` ? undefined : translated;
    }

    /* Sort survives every filter change and page step, so it is merged in here
     * rather than at each of the five call sites. */
    function withSort<T extends Record<string, string | number | undefined>>(params: T) {
        return { ...params, sort: sort.column, direction: sort.direction };
    }

    function submitFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('employees.index'), withSort(filterForm.data), visitOptions);
    }

    /* Clicking a heading re-sorts on that column; clicking the active one flips
     * the direction. Sorting always returns to page one — page 3 of the old
     * order holds different people in the new one. */
    function toggleSort(column: SortColumn) {
        router.get(
            route('employees.index'),
            {
                ...filterForm.data,
                sort: column,
                direction: sort.column === column && sort.direction === 'asc' ? 'desc' : 'asc',
            },
            visitOptions,
        );
    }

    function updateFilter(key: 'organization_id' | 'organization_unit_id' | 'position_id', value: string) {
        if (key === 'organization_id') {
            const next = {
                ...filterForm.data,
                organization_id: value,
                organization_unit_id: '',
                position_id: '',
            };
            filterForm.setData(next);
            router.get(route('employees.index'), withSort(next), visitOptions);
            return;
        }

        if (key === 'organization_unit_id') {
            const next = {
                ...filterForm.data,
                organization_unit_id: value,
                position_id: '',
            };
            filterForm.setData(next);
            router.get(route('employees.index'), withSort(next), visitOptions);
            return;
        }

        filterForm.setData(key, value);
    }

    function goToPage(page: number) {
        router.get(route('employees.index'), withSort({ ...filters, page }), visitOptions);
    }

    /* Clears every filter in one action rather than resetting six controls. */
    function resetFilters() {
        router.get(route('employees.index'), {}, { ...visitOptions, preserveState: false });
    }

    const hasActiveFilters = Object.entries(filterForm.data).some(([key, value]) => value !== ''
        && !(key === 'organization_id' && isOrganizationScoped && value === organizations[0]?.id));
    const filtersChanged = Object.entries(filterForm.data).some(([key, value]) => value !== (filters[key as keyof Filters] ?? ''));
    const countFormat = new Intl.NumberFormat(locale);
    const resultTotal = employees_pagination?.total ?? employees.length;
    const resultStart = employees.length === 0 ? 0 : ((employees_pagination?.current_page ?? 1) - 1) * (employees_pagination?.per_page ?? employees.length) + 1;
    const resultEnd = employees.length === 0 ? 0 : resultStart + employees.length - 1;
    const resultSummary = t('employees.registryResults').replace(':from', countFormat.format(resultStart)).replace(':to', countFormat.format(resultEnd)).replace(':total', countFormat.format(resultTotal));

    /* Columns carry their own sort key so the header row and the query agree on
     * what is sortable; the assignment columns live on a joined table and are
     * deliberately left out. */
    const tableColumns: { label: string; sort?: SortColumn; align?: string }[] = [
        { label: t('employees.employeeNumber'), sort: 'employee_number' },
        { label: t('employees.columnName'), sort: 'full_name' },
        ...(showOrganizationColumn ? [{ label: t('employees.columnOrganization') }] : []),
        { label: t('employees.organizationUnit') },
        { label: t('employees.columnPosition') },
        { label: t('employees.employmentTypeStatus'), sort: 'employment_type' },
        { label: t('employees.systemStatus'), sort: 'status' },
        { label: t('employees.columnFlags') },
        { label: t('common.actions'), align: 'text-right' },
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('employees.title')}
                    description={selectedOrganization ? organizationName(selectedOrganization.name_en, selectedOrganization.name_am) : t('employees.registryScope')}
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

            <div className="min-w-0 space-y-4">
                <section className="rounded-lg border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submitFilters} aria-label={t('employees.registryFilters')}>
                    <fieldset disabled={loading} className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {/*
                          * Each control is labelled for assistive technology.
                          * The visible affordance is the "All …" first option,
                          * which is why no <label> is drawn — but a screen
                          * reader previously reached six unnamed selects.
                          */}
                        <label className="min-w-0 space-y-1.5 sm:col-span-2 xl:col-span-3">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('common.search')}</span>
                        <input
                            type="search"
                            className={inputCls}
                            aria-label={t('employees.searchPlaceholder')}
                            placeholder={t('employees.searchPlaceholder')}
                            value={filterForm.data.search}
                            onChange={(e) => filterForm.setData('search', e.target.value)}
                        />
                        </label>

                        {(!isOrganizationScoped || organizations.length > 1) && (
                            <label className="min-w-0 space-y-1.5">
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('employees.columnOrganization')}</span>
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
                            </label>
                        )}

                        <label className="min-w-0 space-y-1.5">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('employees.organizationUnit')}</span>
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
                        </label>

                        <label className="min-w-0 space-y-1.5">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('employees.columnPosition')}</span>
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
                        </label>

                        <label className="min-w-0 space-y-1.5">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('employees.employmentType')}</span>
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
                        </label>

                        <label className="min-w-0 space-y-1.5">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('employees.systemStatus')}</span>
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
                        </label>
                        <div className="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-3">
                            <button
                                type="submit"
                                className="shrink-0 rounded-control bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
                            >
                                {loading ? t('common.loading') : t('common.filter')}
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
                            <p role="status" className="text-xs text-gray-500 dark:text-slate-400">{filtersChanged ? t('employees.registryApplyHint') : t('employees.registryDependentHint')}</p>
                        </div>
                    </fieldset>
                    </form>
                </section>

                <section aria-busy={loading} className="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('employees.registryTitle')}</h2>
                        <p role="status" aria-live="polite" className="text-xs tabular-nums text-gray-500 dark:text-slate-400">{loading ? t('common.loading') : resultSummary}</p>
                    </div>
                    {employees.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('employees.noEmployeesFound')} description={t('employees.searchFiltersHint')} />
                        </div>
                    ) : (
                        <>
                        <div className="hidden overflow-x-auto md:block">
                            <table className="min-w-full text-left text-sm">
                                <caption className="sr-only">{t('employees.registryTitle')}</caption>
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        {tableColumns.map((column, index) => {
                                            const isSorted = column.sort !== undefined && sort.column === column.sort;

                                            return (
                                                <th
                                                    key={`${column.label}-${index}`}
                                                    scope="col"
                                                    aria-sort={isSorted ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                                    className={`px-4 py-2.5 text-xs font-semibold text-gray-600 dark:text-slate-400 ${column.align ?? ''}`}
                                                >
                                                    {column.sort ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleSort(column.sort as SortColumn)}
                                                            title={t('employees.sortByColumn').replace(':column', column.label)}
                                                            className="inline-flex items-center gap-1 rounded-control font-semibold hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:hover:text-slate-100"
                                                        >
                                                            {column.label}
                                                            {isSorted ? (
                                                                sort.direction === 'asc'
                                                                    ? <ChevronUp className="h-3 w-3" aria-hidden="true" />
                                                                    : <ChevronDown className="h-3 w-3" aria-hidden="true" />
                                                            ) : (
                                                                <ChevronUpDown className="h-3 w-3 text-gray-300 dark:text-slate-600" aria-hidden="true" />
                                                            )}
                                                            <span className="sr-only">
                                                                {isSorted
                                                                    ? t(sort.direction === 'asc' ? 'employees.sortedAscending' : 'employees.sortedDescending')
                                                                    : t('employees.sortByColumn').replace(':column', column.label)}
                                                            </span>
                                                        </button>
                                                    ) : (
                                                        column.label
                                                    )}
                                                </th>
                                            );
                                        })}
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
                                                            <img src={employee.photo_url} alt="" loading="lazy" className="h-9 w-8 rounded-control object-cover" />
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
                                                            {assignment?.assignment_status ? statusLabel(assignment.assignment_status) ?? assignment.assignment_status : t('common.unassigned')}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {/*
                                                      * Flags is a count, so zero is a real answer — "no duplicate
                                                      * matches" — not missing data. It used to render
                                                      * "Not available", which read as though the check had failed
                                                      * to run on every clean employee, i.e. almost all of them.
                                                      */}
                                                    {(employee.duplicate_flags_count ?? 0) > 0 ? (
                                                        <span
                                                            className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                                                            title={t('employees.duplicateFlagsTooltip')}
                                                        >
                                                            <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
                                                            {employee.duplicate_flags_count}
                                                            <span className="sr-only">{t('employees.duplicateFlagsTooltip')}</span>
                                                        </span>
                                                    ) : (
                                                        <span className="text-gray-400 dark:text-slate-500">{t('common.none')}</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-3">
                                                        <Link href={route('employees.show', employee.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                                            {t('common.view')}
                                                        </Link>
                                                        {can.update && <Link href={route('employees.edit', employee.id)} aria-label={`${t('employees.editEmployee')}: ${employeeName(employee)}`} className="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                                                            {t('employees.editEmployee')}
                                                        </Link>}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/*
                          * The table above is desktop-only, so without this the
                          * registry rendered a header and a result count over an
                          * empty panel on every phone. Each card carries the same
                          * identity, placement and status the row does.
                          */}
                        <ul className="divide-y divide-gray-100 md:hidden dark:divide-slate-800">
                            {employees.map((employee) => {
                                const assignment = employee.current_assignment;
                                const contact = employee.phone ?? employee.email ?? t('employees.notAvailable');
                                const unit = assignment?.organization_unit
                                    ? localizedName(assignment.organization_unit.name_en, assignment.organization_unit.name_am, locale)
                                    : t('common.unassigned');
                                const position = assignment?.position
                                    ? localizedName(assignment.position.title_en, assignment.position.title_am, locale)
                                    : t('employees.notAvailable');

                                return (
                                    <li key={employee.id}>
                                        <Link
                                            href={route('employees.show', employee.id)}
                                            className="block p-4 hover:bg-gray-50 dark:hover:bg-slate-800/50"
                                        >
                                            <div className="flex items-start gap-3">
                                                {employee.photo_url ? (
                                                    <img src={employee.photo_url} alt="" loading="lazy" className="h-11 w-10 shrink-0 rounded-control object-cover" />
                                                ) : (
                                                    <span className="flex h-11 w-10 shrink-0 items-center justify-center rounded-control bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                        {employeeName(employee).charAt(0).toUpperCase()}
                                                    </span>
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="truncate font-medium text-gray-900 dark:text-slate-100">{employeeName(employee)}</p>
                                                        <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                                    </div>
                                                    <p className="truncate font-mono text-xs text-gray-500 dark:text-slate-400">{employee.employee_number}</p>
                                                    <p className="mt-1 truncate text-xs text-gray-600 dark:text-slate-300">{position}</p>
                                                    <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                                        {showOrganizationColumn && assignment?.organization
                                                            ? `${organizationName(assignment.organization.name_en, assignment.organization.name_am)} · ${unit}`
                                                            : unit}
                                                    </p>
                                                    <p className="mt-1 truncate text-xs text-gray-400 dark:text-slate-500">{contact}</p>
                                                    {(employee.duplicate_flags_count ?? 0) > 0 && (
                                                        <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                                                            <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
                                                            {employee.duplicate_flags_count}
                                                            <span className="sr-only">{t('employees.duplicateFlagsTooltip')}</span>
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                        </>
                    )}

                    {employees_pagination && employees_pagination.last_page > 1 && (
                        <nav aria-label={t('employees.registryPagination')} className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                {t('common.page')} {employees_pagination.current_page} / {employees_pagination.last_page}
                                {' - '}
                                {employees_pagination.total} {t('common.results')}
                            </p>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={loading || filtersChanged || employees_pagination.current_page <= 1}
                                    onClick={() => goToPage(employees_pagination.current_page - 1)}
                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 dark:hover:border-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300"
                                >
                                    {t('common.previous')}
                                </button>
                                <button
                                    type="button"
                                    disabled={loading || filtersChanged || employees_pagination.current_page >= employees_pagination.last_page}
                                    onClick={() => goToPage(employees_pagination.current_page + 1)}
                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 dark:hover:border-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300"
                                >
                                    {t('common.next')}
                                </button>
                            </div>
                        </nav>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
