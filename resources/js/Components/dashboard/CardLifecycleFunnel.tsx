import EmptyDashboardState from './EmptyDashboardState';

interface Item {
    key: string;
    value: number;
}

interface Props {
    data: Item[];
    t: (key: string) => string;
}

export default function CardLifecycleFunnel({ data, t }: Props) {
    if (data.length === 0) {
        return <EmptyDashboardState compact />;
    }

    const max = Math.max(...data.map((item) => item.value), 1);

    return (
        <div className="space-y-3">
            {data.map((item) => {
                const width = Math.max(15, Math.round((item.value / max) * 100));

                return (
                    <div key={item.key} className="space-y-2">
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <span className="font-medium text-gray-700 dark:text-slate-200">
                                {t(`dashboard.cardLifecycle.${item.key}`)}
                            </span>
                            <span className="font-semibold text-gray-900 dark:text-slate-100">{item.value}</span>
                        </div>
                        <div className="h-10 rounded-card bg-gray-100 p-1 dark:bg-slate-800">
                            <div className="flex h-full items-center rounded-lg bg-[color:var(--color-primary)] px-3 text-sm font-medium text-white" style={{ width: `${width}%` }}>
                                {item.value}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
