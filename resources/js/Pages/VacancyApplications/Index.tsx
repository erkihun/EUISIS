import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/Components/EmptyState';
import { useLocale } from '@/hooks/useLocale';

import { FilterField, FilterPanel, ListPagination, Select, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type Application = {
    id: string;
    application_number: string;
    status: string;
    applied_at: string;
    employee: { name_en: string } | null;
    announcement: { title_en: string } | null;
    // Eloquent serializes the positionEntry relation in snake_case.
    position_entry: {
        organization: { name_en: string } | null;
        position: { title_en: string } | null;
    } | null;
};

/** A plain Laravel length-aware paginator (the controller sends paginate(), not a resource collection). */
type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

type Props = {
    applications: Paginated<Application>;
    filters: { vacancy_announcement_id?: string; status?: string };
};

const STATUSES = ['submitted', 'screened', 'shortlisted', 'selected', 'rejected', 'withdrawn', 'transferred'] as const;

export default function VacancyApplicationsIndex({ applications, filters }: Props) {
    const { t } = useLocale();

    const [status, setStatus] = useState(filters.status ?? '');

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('vacancies.applications')} />}
        >
            <Head title={t('vacancies.applications')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('vacancy-applications.index'), { status: status || undefined }, { preserveState: true, preserveScroll: true })}
                    onReset={() => router.get(route('vacancy-applications.index'))}
                    hasActiveFilters={Boolean(filters.status)}
                >
                    <FilterField label={t('common.status')}>
                        <Select value={status} onChange={e => setStatus(e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            {STATUSES.map(s => (
                                <option key={s} value={s}>
                                    {t(`vacancies.appStatus${s.charAt(0).toUpperCase()}${s.slice(1)}` as never)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[
                                    t('vacancies.applicationNumber'),
                                    t('employees.employee'),
                                    t('vacancies.announcement'),
                                    t('positionEstablishments.position'),
                                    t('positionEstablishments.organization'),
                                    t('vacancies.appliedAt'),
                                    t('common.status'),
                                ].map(h => (
                                    <th key={h} className={cx(tableHeaderCellClassName, 'text-left whitespace-nowrap')}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {applications.data.map(a => (
                                <tr key={a.id} className={tableRowClassName}>
                                    <td className={tableCellClassName}>
                                        <Link href={route('vacancy-applications.show', a.id)} className="font-mono text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                            {a.application_number}
                                        </Link>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{a.employee?.name_en ?? '—'}</td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{a.announcement?.title_en ?? '—'}</td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{a.position_entry?.position?.title_en ?? '—'}</td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{a.position_entry?.organization?.name_en ?? '—'}</td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={a.applied_at} /></td>
                                    <td className={tableCellClassName}><StatusBadge status={a.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    {applications.data.length === 0 && (
                        <EmptyState description={filters.status ? t('common.noMatchingRecords') : undefined} />
                    )}
                    <ListPagination meta={applications} />
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
