import { Head, Link } from '@inertiajs/react';
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
    logs: { data: LogRow[] };
    filters: Record<string, string>;
    applications: { id: string; name: string; code: string }[];
}

export default function ApiManagementLogs({ logs }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.apiLogs')} description={t('apiManagement.logsDescription')} />}>
            <Head title={t('apiManagement.apiLogs')} />

            <div className="mb-4">
                <Link href={route('api-management.index')} className="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    &larr; {t('apiManagement.title')}
                </Link>
            </div>

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
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
                                <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">{log.requested_at ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AuthenticatedLayout>
    );
}
