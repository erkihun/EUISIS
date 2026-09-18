import EmptyDashboardState from './EmptyDashboardState';

interface Item {
    key: string;
    value: number;
}

export default function ProviderRanking({ data }: { data: Item[] }) {
    if (data.length === 0) {
        return <EmptyDashboardState compact />;
    }

    const max = Math.max(...data.map((item) => item.value), 1);

    return (
        <div className="space-y-3">
            {data.map((item, index) => (
                <div key={item.key}>
                    <div className="mb-1 flex items-center justify-between gap-3 text-sm">
                        <div className="flex items-center gap-2">
                            <span className="text-gray-500 dark:text-slate-400">{index + 1}</span>
                            <span className="font-medium text-gray-800 dark:text-slate-100">{item.key}</span>
                        </div>
                        <span className="font-semibold text-gray-900 dark:text-slate-100">{item.value}</span>
                    </div>
                    <div className="h-2 rounded-full bg-gray-100 dark:bg-slate-800">
                        <div
                            className="h-2 rounded-full bg-[color:var(--color-primary)]"
                            style={{ width: `${Math.round((item.value / max) * 100)}%` }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}
