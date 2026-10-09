import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportDashboardStats from '@/Components/transport/TransportDashboardStats';
import TransportTransactionStatusBadge from '@/Components/transport/TransportTransactionStatusBadge';
import { Link } from '@inertiajs/react';

export default function Dashboard({ stats, recentTransactions = [] }: { stats: Record<string, number>; recentTransactions: any[] }) {
    return (
        <TransportProviderLayout title="Transport Dashboard">
            <div className="space-y-6">
                <div className="flex flex-wrap gap-3">
                    <Link href={route('provider.portal.transport.scan')} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Scan ID</Link>
                    <Link href={route('provider.portal.transport.trips.create')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">New Trip</Link>
                    <Link href={route('provider.portal.transport.reports.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">Reports</Link>
                </div>
                <TransportDashboardStats stats={stats} />
                <div className="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {recentTransactions.map((item) => (
                        <div key={item.id} className="flex items-center justify-between border-b border-slate-100 px-4 py-3 text-sm last:border-0 dark:border-slate-800">
                            <div>
                                <p className="font-medium text-slate-900 dark:text-white">{item.employee?.full_name ?? item.result_code}</p>
                                <p className="text-xs text-slate-500">{item.route?.name_en}</p>
                            </div>
                            <TransportTransactionStatusBadge status={item.status} />
                        </div>
                    ))}
                </div>
            </div>
        </TransportProviderLayout>
    );
}
