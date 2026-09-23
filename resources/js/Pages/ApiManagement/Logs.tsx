import { Head, Link, router, useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

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

            <form className="mb-4 flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); form.get(route('api-management.logs')); }}>
                <label className="min-w-0 space-y-1 text-sm dark:text-slate-200">{t('apiManagement.externalApplications')}
                    <select className="block max-w-full rounded-lg border-gray-300 text-sm dark:bg-slate-900" value={form.data.application_id} onChange={e => form.setData('application_id', e.target.value)}>
                        <option value="">{t('apiManagement.allApplications')}</option>
                        {applications.map(app => <option key={app.id} value={app.id}>{app.name}</option>)}
                    </select>
                </label>
                <label className="space-y-1 text-sm dark:text-slate-200">{t('common.status')}
                    <select className="block rounded-lg border-gray-300 text-sm dark:bg-slate-900" value={form.data.status} onChange={e => form.setData('status', e.target.value)}>
                        <option value="">{t('apiManagement.allStatuses')}</option><option value="failed">{t('apiManagement.failedRequests')}</option>
                    </select>
                </label>
                <button disabled={form.processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm text-white">{t('common.filter')}</button>
            </form>
            <section className="overflow-x-auto rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50 dark:bg-slate-950">
                        <tr>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.externalApplications')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.endpoint')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">IP</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('common.status')}</th>
                            <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">{t('apiManagement.requestedAt')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {logs.data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-gray-500 dark:text-slate-400">{t('apiManagement.noLogs')}</td>
                            </tr>
                        ) : logs.data.map((log) => (
                            <tr key={log.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                <td className="px-4 py-2 text-gray-800 dark:text-slate-200">{log.application?.name ?? '—'}</td>
                                <td className="px-4 py-2 font-mono text-[11px] text-gray-600 dark:text-slate-400">
                                    <span className="font-semibold">{log.method}</span> {log.endpoint}
                                </td>
                                <td className="px-4 py-2 font-mono text-[11px] text-gray-500 dark:text-slate-400">{log.ip_address ?? '—'}</td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                        log.success
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'
                                    }`}>
                                        {log.status_code}{log.failure_reason ? ` · ${log.failure_reason}` : ''}
                                    </span>
                                </td>
                                <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={log.requested_at} withTime /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
            {logs.last_page > 1 && <nav aria-label={t('apiManagement.apiLogs')} className="mt-4 flex items-center justify-between gap-3 text-sm dark:text-slate-200">
                <button className="rounded-lg border px-3 py-2 disabled:opacity-50" disabled={logs.current_page <= 1} onClick={() => router.get(route('api-management.logs'), { ...filters, page: logs.current_page - 1 })}>{t('common.previous')}</button>
                <span>{logs.current_page} / {logs.last_page}</span>
                <button className="rounded-lg border px-3 py-2 disabled:opacity-50" disabled={logs.current_page >= logs.last_page} onClick={() => router.get(route('api-management.logs'), { ...filters, page: logs.current_page + 1 })}>{t('common.next')}</button>
            </nav>}
        </AuthenticatedLayout>
    );
}
