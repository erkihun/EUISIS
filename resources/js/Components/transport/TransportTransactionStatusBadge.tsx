export default function TransportTransactionStatusBadge({ status }: { status: string }) {
    const colors: Record<string, string> = {
        accepted: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        rejected: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
        reversed: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        pending_review: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    };

    return <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${colors[status] ?? colors.pending_review}`}>{status}</span>;
}
