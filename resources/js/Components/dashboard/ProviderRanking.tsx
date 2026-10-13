import EmptyDashboardState from './EmptyDashboardState';
import { useChartColors } from '@/hooks/useChartColors';

interface Item {
    key: string;
    value: number;
}

export default function ProviderRanking({ data, emptyTitle }: { data: Item[]; emptyTitle?: string }) {
    const { series } = useChartColors();
    if (data.length === 0) {
        return <EmptyDashboardState compact title={emptyTitle} />;
    }

    const max = Math.max(...data.map((item) => item.value), 1);

    return (
        <div className="space-y-3">
            {data.map((item, index) => (
                <div key={item.key}>
                    <div className="mb-1 flex items-center justify-between gap-3 text-sm">
                        <div className="flex items-center gap-2">
                            <span className="text-[color:var(--app-muted-foreground)]">{index + 1}</span>
                            <span className="font-medium text-[color:var(--app-foreground)]">{item.key}</span>
                        </div>
                        <span className="font-semibold text-[color:var(--app-foreground)]">{item.value}</span>
                    </div>
                    <div className="h-2 rounded-full bg-[color:var(--app-surface-muted)]">
                        <div
                            className="h-2 rounded-full"
                            style={{ width: `${Math.round((item.value / max) * 100)}%`, backgroundColor: series[index % series.length] }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}
