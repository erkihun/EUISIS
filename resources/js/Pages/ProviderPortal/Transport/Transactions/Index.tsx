import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportTransactionStatusBadge from '@/Components/transport/TransportTransactionStatusBadge';
import { Link } from '@inertiajs/react';

export default function Index({ transactions }: { transactions: any }) {
    const rows = transactions?.data ?? transactions ?? [];
    return (
        <TransportProviderLayout title="Transport Transactions">
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {rows.map((item: any) => (
                    <Link key={item.id} href={route('provider.portal.transport.transactions.show', item.id)} className="flex items-center justify-between border-b border-slate-100 px-4 py-3 text-sm last:border-0 dark:border-slate-800">
                        <span>{item.employee?.full_name ?? item.result_code}</span>
                        <TransportTransactionStatusBadge status={item.status} />
                    </Link>
                ))}
            </div>
        </TransportProviderLayout>
    );
}
