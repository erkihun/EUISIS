import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

const DASH = '-';

type Paginated = { data?: Record<string, unknown>[] };

export default function Reports({ summary }: { summary: Paginated | Record<string, unknown>[] }) {
    const { t } = useLocale();
    const rows = Array.isArray(summary) ? summary : (summary?.data ?? []);

    return (
        <AppLayout title={t('reports.title')}>
            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('ledger.period')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('reports.transactions')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.total')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.subsidy')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 ? (
                            <tr><td colSpan={4} className="px-4 py-8 text-center text-slate-500">{t('reports.noData')}</td></tr>
                        ) : rows.map((row, index) => (
                            <tr key={index}>
                                <td className="px-4 py-2 text-slate-700">{String(row.period ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.transactions ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.total_amount ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.total_subsidy ?? DASH)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
