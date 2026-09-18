import TransportTransactionStatusBadge from './TransportTransactionStatusBadge';

export default function TransportScanResultCard({ result }: { result: any }) {
    if (!result) return null;

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{result.message ?? result.result_code}</p>
                {result.transaction?.status && <TransportTransactionStatusBadge status={result.transaction.status} />}
            </div>
            <div className="mt-3 text-sm text-slate-600 dark:text-slate-300">
                <p>{result.transaction?.employee?.full_name ?? result.result_code}</p>
                <p className="font-mono text-xs">{result.transaction?.employee?.employee_number}</p>
            </div>
        </div>
    );
}
