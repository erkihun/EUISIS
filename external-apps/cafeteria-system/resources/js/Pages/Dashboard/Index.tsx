import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Stats = { served_today: number; served_this_month: number; api_failures_today: number };
type Recent = {
    transaction_number: string;
    employee_number: string;
    employee_name: string | null;
    status: string;
    served_at: string | null;
};

export default function Dashboard({ stats, recent }: { stats: Stats; recent: Recent[] }) {
    const { t } = useLocale();
    const cards = [
        { label: 'Served today', value: stats.served_today },
        { label: 'Served this month', value: stats.served_this_month },
        { label: 'API failures today', value: stats.api_failures_today },
    ];

    return (
        <AppLayout title={t('dashboard.title')}>
            <dl className="mb-6 grid gap-3 sm:grid-cols-3">
                {cards.map((card) => (
                    <div key={card.label} className="rounded-2xl border border-gray-200 bg-white p-4">
                        <dd className="text-2xl font-bold tabular-nums text-slate-900">{card.value}</dd>
                        <dt className="mt-1 text-xs text-slate-500">{card.label}</dt>
                    </div>
                ))}
            </dl>

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.transaction')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.employee')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.served')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {recent.length === 0 ? (
                            <tr><td colSpan={4} className="px-4 py-8 text-center text-slate-500">{t('extra.noTransactionsYet')}</td></tr>
                        ) : recent.map((row) => (
                            <tr key={row.transaction_number}>
                                <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.transaction_number}</td>
                                <td className="px-4 py-2 text-slate-800">
                                    {row.employee_name} <span className="font-mono text-xs text-slate-400">{row.employee_number}</span>
                                </td>
                                <td className="px-4 py-2 text-slate-700">{row.status}</td>
                                <td className="px-4 py-2 text-xs text-slate-500">{row.served_at ?? EMDASH}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}

const EMDASH = '—';
