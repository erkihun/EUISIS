import PageHeader from '@/Components/PageHeader';
import PositionOccupancyBreakdown, { type Breakdowns } from '@/Components/positions/PositionOccupancyBreakdown';
import StatusBadge from '@/Components/StatusBadge';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Briefcase, ClipboardCheckIcon, Inbox, TrendingUpIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Button, FilterField, FilterPanel, Card, EmptyState, Input, Pagination, Select, StatusBadge as UiStatusBadge, cx } from '@euisis/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';

import { tableBodyClassName, tableClassName, tableHeadClassName, tableRowClassName } from '@/Components/ui';
type Summary = {
    total_positions: number;
    filled_positions: number;
    vacant_positions: number;
    occupancy_rate: number;
};

type PositionStatusRow = {
    id: string;
    position_id: string;
    establishment_id: string | null;
    establishment_number: string | null;
    job_position_code: string | null;
    title_en: string | null;
    title_am: string | null;
    grade_level: string | null;
    job_family: string | null;
    organization_name_en: string | null;
    organization_name_am: string | null;
    department_name_en: string | null;
    department_name_am: string | null;
    is_active: boolean;
    total_positions: number;
    filled_positions: number;
    vacant_positions: number;
};

type SelectOption = {
    id: string;
    name_en: string | null;
    name_am: string | null;
    organization_id?: string;
};

type PaginatedPositions = {
    data: PositionStatusRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

type Props = {
    summary: Summary;
    breakdowns: Breakdowns;
    positions: PaginatedPositions;
    organizations: SelectOption[];
    organizationUnits: SelectOption[];
    isOrganizationScoped: boolean;
    filters: Record<string, string>;
};

export default function PositionStatus({ summary, breakdowns, positions, organizations, organizationUnits, isOrganizationScoped, filters }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const form = useForm({
        search: filters.search ?? '',
        organization_id: filters.organization_id ?? '',
        organization_unit_id: filters.organization_unit_id ?? '',
        grade_level: filters.grade_level ?? '',
        is_active: filters.is_active ?? '',
        per_page: filters.per_page ?? String(positions.meta.per_page ?? 15),
    });

    const occupancyRate = Math.min(100, Math.max(0, summary.occupancy_rate));

    function optionLabel(option: SelectOption): string {
        return (useAmharic ? option.name_am : option.name_en) ?? option.name_en ?? option.id;
    }

    function submit() {
        router.get(route('positions.status'), form.data, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        router.get(route('positions.status'), {}, { preserveState: true, preserveScroll: true });
    }

    function goToPage(page: number) {
        router.get(route('positions.status'), { ...filters, page }, { preserveState: true, preserveScroll: true });
    }

    function changePageSize(size: number) {
        form.setData('per_page', String(size));
        router.get(route('positions.status'), { ...filters, per_page: size, page: 1 }, { preserveState: true, preserveScroll: true });
    }

    const cell = 'px-4 py-3';

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('positions.newJobPositionsStatus')} description={t('positions.newJobPositionsStatusDescription')} />}
        >
            <Head title={t('positions.newJobPositionsStatus')} />

            <div className="space-y-6">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppMetricCard label={t('positions.totalJobPositions')} value={summary.total_positions.toLocaleString()} variant="primary" icon={<Briefcase className="h-5 w-5" />} />
                    <AppMetricCard label={t('positions.filledPositions')} value={summary.filled_positions.toLocaleString()} variant="success" icon={<ClipboardCheckIcon className="h-5 w-5" />} />
                    <AppMetricCard label={t('positions.vacantPositions')} value={summary.vacant_positions.toLocaleString()} variant={summary.vacant_positions > 0 ? 'warning' : 'neutral'} icon={<Inbox className="h-5 w-5" />} />
                    <AppMetricCard label={t('positions.occupancyRate')} value={`${occupancyRate.toLocaleString()}%`} variant="neutral" icon={<TrendingUpIcon className="h-5 w-5" />}
                        detail={
                            <span className="block h-1.5 w-40 overflow-hidden rounded-full bg-(--app-surface-muted)" role="img" aria-label={`${occupancyRate}%`}>
                                <span className="block h-full rounded-full bg-emerald-500 dark:bg-emerald-400" style={{ width: `${occupancyRate}%` }} />
                            </span>
                        } />
                </section>

                <PositionOccupancyBreakdown breakdowns={breakdowns} />

                <FilterPanel onSubmit={submit} onReset={clearFilters} hasActiveFilters={Object.entries(filters).some(([key, value]) => key !== 'per_page' && key !== 'page' && Boolean(value))}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={form.data.search} placeholder={t('positions.searchPositions')}
                            onChange={(event) => form.setData('search', event.target.value)} />
                    </FilterField>
                    {!isOrganizationScoped && (
                        <FilterField label={t('positions.organization')}>
                            <Select value={form.data.organization_id}
                                onChange={(event) => form.setData({ ...form.data, organization_id: event.target.value, organization_unit_id: '' })}>
                                <option value="">{t('common.all')}</option>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{optionLabel(organization)}</option>)}
                            </Select>
                        </FilterField>
                    )}
                    <FilterField label={t('positions.organizationUnit')}>
                        <Select value={form.data.organization_unit_id} onChange={(event) => form.setData('organization_unit_id', event.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {organizationUnits.map((unit) => <option key={unit.id} value={unit.id}>{optionLabel(unit)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('positions.gradeLevel')}>
                        <Input value={form.data.grade_level} onChange={(event) => form.setData('grade_level', event.target.value)} />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <Card className="overflow-hidden p-0">
                    {positions.data.length === 0 ? (
                        <EmptyState icon={<Briefcase className="h-8 w-8" />} title={t('positions.noPositionStatusFound')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th scope="col" className={cx(cell, 'font-medium')}>{isOrganizationScoped ? t('positions.organizationUnit') : `${t('positions.organizationUnit')} / ${t('positions.organization')}`}</th>
                                        <th scope="col" className={cx(cell, 'font-medium')}>{t('positions.positionTitle')}</th>
                                        <th scope="col" className={cx(cell, 'font-medium')}>{t('positions.gradeLevel')}</th>
                                        <th scope="col" className={cx(cell, 'font-medium')}>{t('common.status')}</th>
                                        <th scope="col" className={cx(cell, 'font-medium')}>{t('positions.occupancy')}</th>
                                        <th scope="col" className={cell}><span className="sr-only">{t('vacancies.announceVacancy')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {positions.data.map((position) => {
                                        const organizationName = (useAmharic ? position.organization_name_am : position.organization_name_en) ?? position.organization_name_en ?? '—';
                                        const departmentName = (useAmharic ? position.department_name_am : position.department_name_en) ?? position.department_name_en ?? t('positions.unassignedDepartment');
                                        const title = (useAmharic ? position.title_am : position.title_en) ?? position.title_en ?? '—';

                                        return (
                                            <tr key={position.id} className={tableRowClassName}>
                                                <td className={cx(cell, 'min-w-56')}>
                                                    <p>{departmentName}</p>
                                                    {!isOrganizationScoped && organizationName !== departmentName && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{organizationName}</p>}
                                                </td>
                                                <td className={cx(cell, 'min-w-48')}>
                                                    <Link href={route('positions.show', position.position_id)} className="font-medium hover:text-primary hover:underline">{title}</Link>
                                                    <p className="mt-0.5 font-mono text-xs text-(--app-muted-foreground)">{position.job_position_code ?? position.establishment_number ?? '—'}</p>
                                                </td>
                                                <td className={cx(cell, 'whitespace-nowrap')}>{position.grade_level ?? '—'}</td>
                                                <td className={cx(cell, 'whitespace-nowrap')}>
                                                    <StatusBadge status={position.is_active ? 'active' : 'inactive'} label={position.is_active ? t('common.active') : t('common.inactive')} />
                                                </td>
                                                <td className={cx(cell, 'whitespace-nowrap')}>
                                                    <UiStatusBadge tone={position.vacant_positions > 0 ? 'warning' : 'success'}>
                                                        {position.vacant_positions > 0 ? t('positions.vacant') : t('positions.filled')}
                                                    </UiStatusBadge>
                                                </td>
                                                <td className={cx(cell, 'whitespace-nowrap text-right')}>
                                                    {position.vacant_positions > 0 && position.establishment_id && (
                                                        <Button as={Link} size="sm" variant="outline" href={route('vacancy-announcements.create', { establishment: position.establishment_id })}>
                                                            {t('vacancies.announceVacancy')}
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination meta={{ currentPage: positions.meta.current_page, lastPage: positions.meta.last_page, perPage: positions.meta.per_page, total: positions.meta.total }}
                        onPageChange={goToPage} onPerPageChange={changePageSize} pageSizes={[10, 15, 25, 50]} className="border-t border-(--app-border) px-4 py-3" />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
