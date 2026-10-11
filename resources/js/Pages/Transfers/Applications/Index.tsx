import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';
import EmptyState from '@/Components/EmptyState';

import { FilterField, FilterPanel, ListPagination, Select, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
type Application = {
    id: string;
    status: string;
    submitted_at: string | null;
    employee: { employee_number: string; full_name: string } | null;
    announcement: { position: { title_en: string; title_am: string | null } | null } | null;
    releasingOrganization: { name_en: string; name_am: string | null } | null;
    receivingOrganization: { name_en: string; name_am: string | null } | null;
};

type Props = {
    applications: LaravelPageMeta & { data: Application[] };
    filters: { status?: string };
};

const STATUSES = [
    'submitted', 'under_review', 'verified', 'selected',
    'release_pending', 'receiving_pending', 'final_approval_pending',
    'approved', 'transferred', 'rejected', 'withdrawn', 'cancelled',
] as const;

export default function TransferApplicationsIndex({ applications, filters }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const [status, setStatus] = useState(filters.status ?? '');

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfers.dashboard')}
                    title={t('transfers.applications')}
                />
            }
        >
            <Head title={t('transfers.applications')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('transfer-applications.index'), status ? { status } : {}, { preserveState: true })}
                    onReset={() => router.get(route('transfer-applications.index'))}
                    hasActiveFilters={Boolean(filters.status)}
                >
                    <FilterField label={t('common.status')}>
                        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            {STATUSES.map((s) => {
                                const key = `transfers.status${s.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never;
                                return <option key={s} value={s}>{t(key)}</option>;
                            })}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('transfers.position'), t('common.employee'), t('transfers.releasingOrganization'), t('transfers.receivingOrganization'), t('common.submittedAt'), t('common.status')].map((h) => (
                                    <th key={h} className={cx(tableHeaderCellClassName, 'text-left whitespace-nowrap')}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {applications.data.map((app) => (
                                <tr key={app.id} className={tableRowClassName}>
                                    <td className={tableCellClassName}>
                                        <Link href={route('transfer-applications.show', app.id)} className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                            {(useAmharic ? app.announcement?.position?.title_am : null) ?? app.announcement?.position?.title_en ?? '-'}
                                        </Link>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>
                                        {app.employee?.full_name}
                                        <span className="ml-1 text-xs text-[color:var(--app-muted-foreground)]">{app.employee?.employee_number}</span>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                        {(useAmharic ? app.releasingOrganization?.name_am : null) ?? app.releasingOrganization?.name_en ?? '-'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                        {(useAmharic ? app.receivingOrganization?.name_am : null) ?? app.receivingOrganization?.name_en ?? '-'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={app.submitted_at} fallback="-" /></td>
                                    <td className={tableCellClassName}><StatusBadge status={app.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    {applications.data.length === 0 && <EmptyState title={t('transfers.noApplications')} description={filters.status ? t('common.noMatchingRecords') : undefined} />}
                    <ListPagination meta={applications} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
