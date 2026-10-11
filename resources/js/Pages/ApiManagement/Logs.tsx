import { Head, Link, router, useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

import { FilterField, FilterPanel, ListPagination, Select, badgeClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type LogRow = {
    id: string;
    application: { id: string; name: string; code: string } | null;
    endpoint: string;
    method: string;
    ip_address: string | null;
    status_code: number;
    success: boolean;
    failure_reason: string | null;
    requested_at: string | null;
};

interface Props {
    logs: { data: LogRow[]; current_page: number; last_page: number; total: number };
    filters: Record<string, string>;
    applications: { id: string; name: string; code: string }[];
}

export default function ApiManagementLogs({ logs, filters, applications }: Props) {
    const { t } = useLocale();
    const form = useForm({ application_id: filters.application_id ?? '', status: filters.status ?? '' });

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.apiLogs')} description={t('apiManagement.logsDescription')} />}>
            <Head title={t('apiManagement.apiLogs')} />

            <div className="mb-4">
                <Link href={route('api-management.index')} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                    &larr; {t('apiManagement.title')}
                </Link>
            </div>

            <FilterPanel
                onSubmit={() => form.get(route('api-management.logs'), { preserveState: true, preserveScroll: true })}
                onReset={() => router.get(route('api-management.logs'))}
                hasActiveFilters={Boolean(filters.application_id || filters.status)}
                processing={form.processing}
                className="mb-4"
            >
                <FilterField label={t('apiManagement.externalApplications')}>
                    <Select value={form.data.application_id} onChange={e => form.setData('application_id', e.target.value)}>
                        <option value="">{t('apiManagement.allApplications')}</option>
                        {applications.map(app => <option key={app.id} value={app.id}>{app.name}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={form.data.status} onChange={e => form.setData('status', e.target.value)}>
                        <option value="">{t('apiManagement.allStatuses')}</option><option value="failed">{t('apiManagement.failedRequests')}</option>
                    </Select>
                </FilterField>
            </FilterPanel>
            <section className={tableContainerClassName}>
                <div className="overflow-x-auto">
                <table className={cx(tableClassName, 'min-w-full text-left')}>
                    <thead className={tableHeadClassName}>
                        <tr>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('apiManagement.externalApplications')}</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('apiManagement.endpoint')}</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>IP</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('common.status')}</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('apiManagement.requestedAt')}</th>
                        </tr>
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {logs.data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className={cx(tableCellClassName, 'text-center text-[color:var(--app-muted-foreground)]')}>{t('apiManagement.noLogs')}</td>
                            </tr>
                        ) : logs.data.map((log) => (
                            <tr key={log.id} className={tableRowClassName}>
                                <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{log.application?.name ?? '—'}</td>
                                <td className={cx(tableCellClassName, 'font-mono text-[color:var(--app-muted-foreground)]')}>
                                    <span className="font-semibold">{log.method}</span> {log.endpoint}
                                </td>
                                <td className={cx(tableCellClassName, 'font-mono text-[color:var(--app-muted-foreground)]')}>{log.ip_address ?? '—'}</td>
                                <td className={tableCellClassName}>
                                    <span className={badgeClassName(log.success ? 'success' : 'danger')}>
                                        {log.status_code}{log.failure_reason ? ` · ${log.failure_reason}` : ''}
                                    </span>
                                </td>
                                <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={log.requested_at} withTime /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                </div>
                <ListPagination meta={logs} />
            </section>
        </AuthenticatedLayout>
    );
}
