import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

const DASH = '-';

type Paginated = { data?: Record<string, unknown>[] };

export default function ApiLogs({ logs }: { logs: Paginated | Record<string, unknown>[] }) {
    const { t } = useLocale();
    const rows = Array.isArray(logs) ? logs : (logs?.data ?? []);

    return (
        <AppLayout title={t('apiLogs.title')}>
            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('apiLogs.method')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('apiLogs.endpoint')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('apiLogs.statusCode')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('apiLogs.errorCode')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">ms</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.when')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 ? (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">{t('apiLogs.noLogs')}</td></tr>
                        ) : rows.map((row, index) => (
                            <tr key={index}>
                                <td className="px-4 py-2 text-slate-700">{String(row.method ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.endpoint ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.status_code ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.error_code ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.duration_ms ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.requested_at ?? DASH)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
