import { useEffect, useState } from 'react';
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

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
    has_active_card?: boolean;
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
    /** More matches than the deepest page shows: ask for a search or filter. */
    page_limit_reached?: boolean;
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
     * Filters apply with the Filter button (or Enter), as on every admin list:
     * one query per intent rather than one per keystroke against the whole
     * registry. Organization and unit are the exception: the unit and
     * position options come from the server, so choosing one reloads them.
     */
    const visitOptions = {
        preserveState: true, preserveScroll: true,
        onStart: () => setLoading(true), onFinish: () => setLoading(false),
        onError: () => toast.error(t('employees.registryLoadError')),
    };

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

    function submitFilters() {
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
                                    className="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-gray-300 px-3 py-2 text-sm font-medium text-(--app-muted-foreground) dark:bg-slate-700"
                                >
                                    <Plus className="h-4 w-4" />
                                    {t('employees.addNewEmployee')}
                                </button>
                            ) : (
                                <Link
                                    href={createHref}
                                    className={buttonClassName({ variant: 'primary' })}
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
                <FilterPanel
                    label={t('employees.registryFilters')}
                    onSubmit={submitFilters}
                    onReset={resetFilters}
                    hasActiveFilters={hasActiveFilters}
                    processing={loading}
                    submitLabel={loading ? t('common.loading') : t('common.filter')}
                    footer={<span role="status">{filtersChanged ? t('employees.registryApplyHint') : t('employees.registryDependentHint')}</span>}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" placeholder={t('employees.searchPlaceholder')} value={filterForm.data.search} onChange={(e) => filterForm.setData('search', e.target.value)} />
                    </FilterField>
                    {(!isOrganizationScoped || organizations.length > 1) && (
                        <FilterField label={t('employees.columnOrganization')}>
                            <Select value={filterForm.data.organization_id} onChange={(e) => updateFilter('organization_id', e.target.value)}>
                                <option value="">{t('employees.allOrganizations')}</option>
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>
                                        {organizationName(organization.name_en, organization.name_am)}
                                    </option>
                                ))}
                            </Select>
                        </FilterField>
                    )}
                    <FilterField label={t('employees.organizationUnit')}>
                        <Select value={filterForm.data.organization_unit_id} onChange={(e) => updateFilter('organization_unit_id', e.target.value)}>
                            <option value="">{t('employees.allOrganizationUnits')}</option>
                            {organizationUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.code ? `${unit.code} - ` : ''}
                                    {localizedName(unit.name_en ?? '', unit.name_am, locale)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('employees.columnPosition')}>
                        <Select value={filterForm.data.position_id} onChange={(e) => updateFilter('position_id', e.target.value)}>
                            <option value="">{t('employees.allPositions')}</option>
                            {positions.map((position) => (
                                <option key={position.id} value={position.id}>
                                    {position.job_position_code ? `${position.job_position_code} - ` : ''}
                                    {localizedName(position.title_en ?? '', position.title_am, locale)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('employees.employmentType')}>
                        <Select value={filterForm.data.employment_type} onChange={(e) => filterForm.setData('employment_type', e.target.value)}>
                            <option value="">{t('employees.allEmploymentTypes')}</option>
                            {EMPLOYMENT_TYPES.map((type) => (
                                <option key={type} value={type}>
                                    {t(`employees.employmentType_${type}`)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('employees.systemStatus')}>
                        <Select value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                            <option value="">{t('employees.allStatuses')}</option>
                            {SYSTEM_STATUSES.map((status) => (
                                <option key={status} value={status}>
                                    {t(`employees.${status}`)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section aria-busy={loading} className={cx(tableContainerClassName, 'min-w-0')}>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{t('employees.registryTitle')}</h2>
                    </div>
                    {employees.length === 0 ? (
                        <EmptyState title={t('employees.noEmployeesFound')} description={t('employees.searchFiltersHint')} />
                    ) : (
                        <>
                        <div className="hidden overflow-x-auto md:block">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <caption className="sr-only">{t('employees.registryTitle')}</caption>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {tableColumns.map((column, index) => {
                                            const isSorted = column.sort !== undefined && sort.column === column.sort;

                                            return (
                                                <th
                                                    key={`${column.label}-${index}`}
                                                    scope="col"
                                                    aria-sort={isSorted ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                                    className={`px-4 py-2.5 text-xs font-semibold text-(--app-muted-foreground) ${column.align ?? ''}`}
                                                >
                                                    {column.sort ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleSort(column.sort as SortColumn)}
                                                            title={t('employees.sortByColumn').replace(':column', column.label)}
                                                            className="inline-flex items-center gap-1 rounded-control font-semibold hover:text-(--app-foreground) focus:outline-hidden focus:ring-2 focus:ring-primary"
                                                        >
                                                            {column.label}
                                                            {isSorted ? (
                                                                sort.direction === 'asc'
                                                                    ? <ChevronUp className="h-3 w-3" aria-hidden="true" />
                                                                    : <ChevronDown className="h-3 w-3" aria-hidden="true" />
                                                            ) : (
                                                                <ChevronUpDown className="h-3 w-3 text-(--app-muted-foreground)" aria-hidden="true" />
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
                                <tbody className={tableBodyClassName}>
                                    {employees.map((employee) => {
                                        const assignment = employee.current_assignment;
                                        const contact = employee.phone ?? employee.email ?? t('employees.notAvailable');

                                        return (
                                            <tr key={employee.id} className={tableRowClassName}>
                                                <td className={cx(tableCellClassName, 'whitespace-nowrap')}>
                                                    <Link
                                                        href={route('employees.show', employee.id)}
                                                        className="font-medium text-primary hover:text-primary-hover dark:text-primary"
                                                    >
                                                        {employee.employee_number}
                                                    </Link>
                                                </td>
                                                <td className={tableCellClassName}>
                                                    <div className="flex min-w-56 items-center gap-3">
                                                        {employee.photo_url ? (
                                                            <img src={employee.photo_url} alt="" loading="lazy" className="h-9 w-8 rounded-control object-cover" />
                                                        ) : (
                                                            <span className="flex h-9 w-8 items-center justify-center rounded-control bg-(--app-surface-muted) text-xs font-semibold text-(--app-muted-foreground)">
                                                                {employeeName(employee).charAt(0).toUpperCase()}
                                                            </span>
                                                        )}
                                                        <div className="min-w-0">
                                                            <p className="truncate font-medium text-(--app-foreground)">{employeeName(employee)}</p>
                                                            <p className="truncate text-xs text-(--app-muted-foreground)">{contact}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                {showOrganizationColumn && (
                                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                                        {assignment?.organization
                                                            ? organizationName(assignment.organization.name_en, assignment.organization.name_am)
                                                            : t('common.unassigned')}
                                                    </td>
                                                )}
                                                <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                                    {assignment?.organization_unit ? (
                                                        <>
                                                            {assignment.organization_unit.code ? `${assignment.organization_unit.code} - ` : ''}
                                                            {localizedName(assignment.organization_unit.name_en, assignment.organization_unit.name_am, locale)}
                                                        </>
                                                    ) : (
                                                        t('common.unassigned')
                                                    )}
                                                </td>
                                                <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                                    {assignment?.position ? (
                                                        <>
                                                            {assignment.position.job_position_code ? `${assignment.position.job_position_code} - ` : ''}
                                                            {localizedName(assignment.position.title_en, assignment.position.title_am, locale)}
                                                        </>
                                                    ) : (
                                                        t('employees.notAvailable')
                                                    )}
                                                </td>
                                                <td className={tableCellClassName}>
                                                    <div className="space-y-1">
                                                        <p className="text-(--app-foreground)">
                                                            {employee.employment_type
                                                                ? t(`employees.employmentType_${employee.employment_type}`)
                                                                : t('employees.notAvailable')}
                                                        </p>
                                                        <p className="text-xs text-(--app-muted-foreground)">
                                                            {assignment?.assignment_status ? statusLabel(assignment.assignment_status) ?? assignment.assignment_status : t('common.unassigned')}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className={tableCellClassName}>
                                                    <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                                    {employee.has_active_card !== undefined && (
                                                        <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                                            {employee.has_active_card ? t('employees.activeCard') : t('employees.noActiveCard')}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className={tableCellClassName}>
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
                                                        <span className="text-(--app-muted-foreground)">{t('common.none')}</span>
                                                    )}
                                                </td>
                                                <td className={cx(tableCellClassName, 'whitespace-nowrap text-right')}>
                                                    <div className="flex justify-end">
                                                        <AppActionMenu
                                                            items={[
                                                                { label: t('common.view'), href: route('employees.show', employee.id) },
                                                                { label: t('common.edit'), href: route('employees.edit', employee.id), show: can.update },
                                                            ]}
                                                        />
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
                        <ul className="divide-y divide-(--app-border) md:hidden">
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
                                            className="block p-4 hover:bg-(--app-surface-muted)"
                                        >
                                            <div className="flex items-start gap-3">
                                                {employee.photo_url ? (
                                                    <img src={employee.photo_url} alt="" loading="lazy" className="h-11 w-10 shrink-0 rounded-control object-cover" />
                                                ) : (
                                                    <span className="flex h-11 w-10 shrink-0 items-center justify-center rounded-control bg-(--app-surface-muted) text-sm font-semibold text-(--app-muted-foreground)">
                                                        {employeeName(employee).charAt(0).toUpperCase()}
                                                    </span>
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="truncate font-medium text-(--app-foreground)">{employeeName(employee)}</p>
                                                        <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                                    </div>
                                                    <p className="truncate font-mono text-xs text-(--app-muted-foreground)">{employee.employee_number}</p>
                                                    <p className="mt-1 truncate text-xs text-(--app-muted-foreground)">{position}</p>
                                                    <p className="truncate text-xs text-(--app-muted-foreground)">
                                                        {showOrganizationColumn && assignment?.organization
                                                            ? `${organizationName(assignment.organization.name_en, assignment.organization.name_am)} · ${unit}`
                                                            : unit}
                                                    </p>
                                                    <p className="mt-1 truncate text-xs text-(--app-muted-foreground)">{contact}</p>
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
                    <ListPagination meta={employees_pagination} label={t('employees.registryPagination')} disabled={loading || filtersChanged} onPageChange={goToPage} />
                    {employees_pagination?.page_limit_reached && (
                        <p className="px-4 pb-4 text-sm text-muted-foreground">{t('employees.registryPageLimit')}</p>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
