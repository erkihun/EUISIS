import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

const DASH = '-';

type Paginated = { data?: Record<string, unknown>[] };

export default function Transactions({ transactions }: { transactions: Paginated | Record<string, unknown>[] }) {
    const { t } = useLocale();
    const rows = Array.isArray(transactions) ? transactions : (transactions?.data ?? []);

    return (
        <AppLayout title={t('transactions.title')}>
            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('transactions.transactionNumber')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.employeeNumber')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.employee')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.served')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('transactions.noTransactions')}</td></tr>
                        ) : rows.map((row, index) => (
                            <tr key={index}>
                                <td className="px-4 py-2 text-slate-700">{String(row.transaction_number ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.employee_number ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.employee_name ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.status ?? DASH)}</td>
                                <td className="px-4 py-2 text-slate-700">{String(row.served_at ?? DASH)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
