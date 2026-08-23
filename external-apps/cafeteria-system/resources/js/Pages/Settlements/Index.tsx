import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

const DASH = '-';

type Paginated = { data?: Record<string, unknown>[] };

export default function Settlements({ settlements }: { settlements: Paginated | Record<string, unknown>[] }) {
    const { t } = useLocale();
    const rows = Array.isArray(settlements) ? settlements : (settlements?.data ?? []);

    return (
        <AppLayout title={t('settlements.title')}>
            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.from')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">To</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('settlements.transactionCount')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.total')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('settlements.noSettlements')}</td></tr>
                        ) : rows.map((row, index) => (
                            <tr key={index}>
                                <td className="px-4 py-2 text-slate-700">{String(row.period_start ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.period_end ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.transaction_count ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.total_amount ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.status ?? DASH)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
