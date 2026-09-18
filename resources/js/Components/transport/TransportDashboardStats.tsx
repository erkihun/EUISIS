type Stats = Record<string, number>;

export default function TransportDashboardStats({ stats }: { stats: Stats }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {Object.entries(stats).map(([key, value]) => (
                <div key={key} className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-xs font-semibold uppercase text-slate-500">{key.replaceAll('_', ' ')}</p>
                    <p className="mt-2 text-2xl font-bold tabular-nums text-slate-950 dark:text-white">{value}</p>
                </div>
            ))}
        </div>
    );
}
