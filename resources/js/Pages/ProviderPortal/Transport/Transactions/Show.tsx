import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportTransactionStatusBadge from '@/Components/transport/TransportTransactionStatusBadge';

export default function Show({ transaction }: { transaction: any }) {
    return (
        <TransportProviderLayout title="Transport Transaction">
            <div className="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between"><h1 className="text-lg font-semibold">{transaction.employee?.full_name}</h1><TransportTransactionStatusBadge status={transaction.status} /></div>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">Employee Number</dt><dd>{transaction.employee?.employee_number}</dd></div>
                    <div><dt className="text-slate-500">Route</dt><dd>{transaction.route?.name_en}</dd></div>
                    <div><dt className="text-slate-500">Trip</dt><dd>{transaction.trip?.trip_number}</dd></div>
                    <div><dt className="text-slate-500">Result</dt><dd>{transaction.result_code}</dd></div>
                </dl>
            </div>
        </TransportProviderLayout>
    );
}
