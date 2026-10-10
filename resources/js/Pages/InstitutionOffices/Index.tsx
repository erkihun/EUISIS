import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

    const inputCls = inputClassName;

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
                                className={buttonClassName({ variant: 'primary' })}
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

            <section className="rounded-lg border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
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
                                        className={cx(tableHeaderCellClassName, 'text-left')}
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {offices.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className={cx(tableCellClassName, 'text-center text-[color:var(--app-muted-foreground)]')}
                                    >
                                        {t('institutionOffices.noOffices')}
                                    </td>
                                </tr>
                            ) : (
                                offices.data.map((office) => (
                                    <tr
                                        key={office.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={cx(tableCellClassName, 'font-mono text-xs')}>{office.code}</td>
                                        <td className={tableCellClassName}>
                                            <div className="font-medium">{office.name_en ?? t('institutionOffices.notSelected')}</div>
                                            {office.name_am && (
                                                <div className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    {office.name_am}
                                                </div>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {office.organization?.name_en ?? t('institutionOffices.notSelected')}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {office.parent ? (
                                                <Link
                                                    href={route('organization-units.show', office.parent.id)}
                                                    className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {office.parent.name_en}
                                                </Link>
                                            ) : (
                                                <span className="text-[color:var(--app-muted-foreground)]">{t('institutionOffices.notSelected')}</span>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {office.functional_reporting?.name_en ?? t('institutionOffices.notSelected')}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={office.status} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('organization-units.show', office.id)}
                                                    className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {t('common.view')}
                                                </Link>
                                                <Link
                                                    href={route('organization-units.edit', office.id)}
                                                    className="text-xs text-[color:var(--app-muted-foreground)] hover:underline"
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
                    <div className="flex items-center justify-between border-t border-[color:var(--app-border)] px-4 py-3">
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                            {offices.from}-{offices.to} / {offices.total}
                        </p>
                        <div className="flex gap-2">
                            {offices.prev_page_url && (
                                <Link
                                    href={offices.prev_page_url}
                                    className="rounded-md border border-[color:var(--app-border-strong)] px-3 py-1 text-xs hover:bg-[color:var(--app-surface-muted)]"
                                >
                                    {t('common.previous')}
                                </Link>
                            )}
                            {offices.next_page_url && (
                                <Link
                                    href={offices.next_page_url}
                                    className="rounded-md border border-[color:var(--app-border-strong)] px-3 py-1 text-xs hover:bg-[color:var(--app-surface-muted)]"
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
