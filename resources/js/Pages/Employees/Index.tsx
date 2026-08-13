import { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import ScopedOrganizationStructure, { type ScopedOrganization } from '@/Components/organization-structure/ScopedOrganizationStructure';
import { AlertTriangle, Building2, Plus, Users } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationSummary } from '@/types/organizationUnit';

type PositionOption = {
    id: string;
    job_position_code: string | null;
    title_en: string;
    title_am: string | null;
    organization_id?: string;
    organization_unit_id?: string | null;
    occupancy_status?: 'vacant' | 'occupied';
};

type EmployeeRow = {
    id: string;
    employee_number: string;
    full_name: string;
    phone: string | null;
    email: string | null;
    photo_url: string | null;
    status: string;
    duplicate_flags_count?: number;
    current_assignment?: {
        organization?: { name_en: string; name_am?: string | null } | null;
        organization_unit?: { code: string | null; name_en: string; name_am?: string | null } | null;
        position?: { title_en: string; title_am?: string | null } | null;
    } | null;
};

type EmployeesPagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

interface Props {
    organizationStructure: ScopedOrganization[];
    isOrganizationScoped: boolean;
    selectedOrganization: OrganizationSummary | null;
    selectedPosition: PositionOption | null;
    employees: EmployeeRow[];
    employees_pagination?: EmployeesPagination;
    filters: { search?: string; status?: string; organization_id?: string; position_id?: string };
    can: { create: boolean };
}

export default function EmployeesIndex({
    organizationStructure,
    isOrganizationScoped,
    selectedOrganization,
    selectedPosition,
    employees,
    employees_pagination,
    filters,
    can,
}: Props) {
    const { t, locale } = useLocale();

    /** Localized status label, falling back to StatusBadge's own default when a key is missing. */
    function statusLabel(namespace: 'employees' | 'common', status: string): string | undefined {
        const key = `${namespace}.${status}`;
        const translated = t(key);
        return translated === key ? undefined : translated;
    }

    const filterForm = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
    });

    const displayOrg = selectedOrganization;

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

    function selectOrganizationId(organizationId: string) {
        router.get(
            route('employees.index'),
            { organization_id: organizationId },
            { preserveState: false, preserveScroll: false },
        );
    }

    function selectScopedPosition(organizationId: string, positionId: string) {
        router.get(
            route('employees.index'),
            { organization_id: organizationId, position_id: positionId },
            { preserveState: true, preserveScroll: true },
        );
    }

    function clearPosition() {
        router.get(
            route('employees.index'),
            { organization_id: displayOrg?.id ?? '' },
            { preserveState: true, preserveScroll: true },
        );
    }

    function submitFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(
            route('employees.index'),
            {
                ...filterForm.data,
                organization_id: displayOrg?.id ?? '',
                ...(selectedPosition ? { position_id: selectedPosition.id } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    // Backend re-checks this on the create route; the disabled button is only
    // there to explain the block before the user navigates.
    const selectedPositionIsOccupied = selectedPosition?.occupancy_status === 'occupied';

    const createHref =
        route('employees.create') +
        '?organization_id=' +
        (displayOrg?.id ?? '') +
        (selectedPosition?.organization_unit_id ? '&organization_unit_id=' + selectedPosition.organization_unit_id : '') +
        (selectedPosition ? '&position_id=' + selectedPosition.id : '');

    return (
        <AuthenticatedLayout header={<PageHeader title={t('employees.title')} />}>
            <Head title={t('employees.title')} />

            <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
                <div className="w-full lg:min-h-[600px] lg:w-[36%]">
                    <ScopedOrganizationStructure
                        organizations={organizationStructure}
                        isScoped={isOrganizationScoped}
                        selectedOrganizationId={displayOrg?.id ?? null}
                        selectedPositionId={selectedPosition?.id ?? null}
                        onSelectOrganization={selectOrganizationId}
                        onSelectPosition={selectScopedPosition}
                        onClearPosition={clearPosition}
                    />
                </div>

                <div className="w-full lg:flex-1">
                    {displayOrg ? (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">
                                        {selectedPosition ? t('employees.selectedPosition') : t('employees.selectedOrganization')}
                                    </p>
                                    <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {selectedPosition
                                            ? localizedName(selectedPosition.title_en, selectedPosition.title_am, locale)
                                            : localizedName(displayOrg.name_en, displayOrg.name_am, locale)}
                                    </p>
                                </div>
                                {can.create && (
                                    selectedPositionIsOccupied ? (
                                        <button
                                            type="button"
                                            onClick={() => toast.error(t('employees.positionOccupiedCannotCreate'))}
                                            title={t('employees.positionOccupiedCannotCreate')}
                                            aria-disabled="true"
                                            className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 dark:bg-slate-700 dark:text-slate-400"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            {t('employees.createEmployee')}
                                        </button>
                                    ) : (
                                        <Link
                                            href={createHref}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            {t('employees.createEmployee')}
                                        </Link>
                                    )
                                )}
                            </div>

                            <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                <form className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_220px_auto]" onSubmit={submitFilters}>
                                    <input
                                        className={inputCls}
                                        placeholder={t('employees.searchPlaceholder')}
                                        value={filterForm.data.search}
                                        onChange={(e) => filterForm.setData('search', e.target.value)}
                                    />
                                    <select
                                        className={inputCls}
                                        value={filterForm.data.status}
                                        onChange={(e) => filterForm.setData('status', e.target.value)}
                                    >
                                        <option value="">{t('employees.allStatuses')}</option>
                                        <option value="active">{t('employees.active')}</option>
                                        <option value="suspended">{t('employees.suspended')}</option>
                                        <option value="transferred">{t('employees.transferred')}</option>
                                        <option value="retired">{t('employees.retired')}</option>
                                    </select>
                                    <button
                                        type="submit"
                                        className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        {t('common.filter')}
                                    </button>
                                </form>
                            </section>

                            <section className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                                {employees.length === 0 ? (
                                    <div className="p-6">
                                        <EmptyState
                                            title={t('employees.noEmployeesFound')}
                                            description={t('employees.searchFiltersHint')}
                                        />
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-left text-sm">
                                            <thead className="bg-gray-50 dark:bg-slate-950">
                                                <tr>
                                                    {[
                                                        t('employees.employeeNumber'),
                                                        t('employees.columnName'),
                                                        t('employees.columnStatus'),
                                                        t('employees.columnOrganization'),
                                                        t('employees.columnPosition'),
                                                        t('employees.columnFlags'),
                                                        '',
                                                    ].map((heading, index) => (
                                                        <th
                                                            key={index}
                                                            className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400"
                                                        >
                                                            {heading}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {employees.map((employee) => (
                                                    <tr
                                                        key={employee.id}
                                                        className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                                    >
                                                        <td className="px-4 py-3">
                                                            <Link
                                                                href={route('employees.show', employee.id)}
                                                                className="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                            >
                                                                {employee.employee_number}
                                                            </Link>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-3">
                                                                {employee.photo_url ? (
                                                                    <img
                                                                        src={employee.photo_url}
                                                                        alt=""
                                                                        className="h-9 w-8 rounded-lg object-cover"
                                                                    />
                                                                ) : (
                                                                    <span className="flex h-9 w-8 items-center justify-center rounded-lg bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                                        {employee.full_name.charAt(0).toUpperCase()}
                                                                    </span>
                                                                )}
                                                                <div>
                                                                    <p className="font-medium text-gray-900 dark:text-slate-100">
                                                                        {employee.full_name}
                                                                    </p>
                                                                    <p className="text-xs text-gray-400 dark:text-slate-500">
                                                                        {employee.phone ?? employee.email ?? t('employees.notAvailable')}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={employee.status}
                                                                label={statusLabel('employees', employee.status)}
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                            <div>
                                                                <p>
                                                                    {employee.current_assignment?.organization
                                                                        ? localizedName(
                                                                            employee.current_assignment.organization.name_en,
                                                                            employee.current_assignment.organization.name_am,
                                                                            locale,
                                                                        )
                                                                        : t('common.unassigned')}
                                                                </p>
                                                                {employee.current_assignment?.organization_unit ? (
                                                                    <p className="text-xs text-gray-400 dark:text-slate-500">
                                                                        {employee.current_assignment.organization_unit.code ? `${employee.current_assignment.organization_unit.code} — ` : ''}
                                                                        {localizedName(
                                                                            employee.current_assignment.organization_unit.name_en,
                                                                            employee.current_assignment.organization_unit.name_am,
                                                                            locale,
                                                                        )}
                                                                    </p>
                                                                ) : null}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                            {employee.current_assignment?.position
                                                                ? localizedName(
                                                                    employee.current_assignment.position.title_en,
                                                                    employee.current_assignment.position.title_am,
                                                                    locale,
                                                                )
                                                                : t('employees.notAvailable')}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {(employee.duplicate_flags_count ?? 0) > 0 ? (
                                                                <span className="inline-flex items-center gap-1 text-orange-600 dark:text-orange-400">
                                                                    <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
                                                                    {employee.duplicate_flags_count}
                                                                </span>
                                                            ) : (
                                                                <span className="text-gray-400 dark:text-slate-500">{t('employees.notAvailable')}</span>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-3">
                                                                <Link
                                                                    href={route('employees.show', employee.id)}
                                                                    className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                                                >
                                                                    {t('common.view')}
                                                                </Link>
                                                                <Link
                                                                    href={route('employees.edit', employee.id)}
                                                                    className="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                                                                >
                                                                    {t('employees.editEmployee')}
                                                                </Link>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                                {employees_pagination && employees_pagination.last_page > 1 && (
                                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                            {t('common.page')} {employees_pagination.current_page} / {employees_pagination.last_page}
                                            {' · '}
                                            {employees_pagination.total} {t('common.results')}
                                        </p>
                                        <div className="flex gap-2">
                                            {employees_pagination.current_page > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() => router.get(route('employees.index'), {
                                                        ...filters,
                                                        page: employees_pagination.current_page - 1,
                                                    }, { preserveState: true, preserveScroll: true })}
                                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300"
                                                >
                                                    {t('common.previous')}
                                                </button>
                                            )}
                                            {employees_pagination.current_page < employees_pagination.last_page && (
                                                <button
                                                    type="button"
                                                    onClick={() => router.get(route('employees.index'), {
                                                        ...filters,
                                                        page: employees_pagination.current_page + 1,
                                                    }, { preserveState: true, preserveScroll: true })}
                                                    className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300"
                                                >
                                                    {t('common.next')}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>
                        </div>
                    ) : (
                        <div className="flex h-full min-h-[300px] flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-900">
                            <Users className="h-8 w-8 text-gray-300 dark:text-slate-600" />
                            <p className="mt-2 text-sm text-gray-500 dark:text-slate-400">
                                {t('employees.selectOrganizationToViewEmployees')}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
