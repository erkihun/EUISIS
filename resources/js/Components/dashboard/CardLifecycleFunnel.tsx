import EmptyDashboardState from './EmptyDashboardState';
import { useChartColors } from '@/hooks/useChartColors';

interface Item {
    key: string;
    value: number;
}

interface Props {
    data: Item[];
    t: (key: string) => string;
}

export default function CardLifecycleFunnel({ data, t }: Props) {
    const { series } = useChartColors();
    if (data.length === 0) {
        return <EmptyDashboardState compact />;
    }

    const max = Math.max(...data.map((item) => item.value), 1);

    return (
        <div className="space-y-3">
            {data.map((item, index) => {
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
                            <div className="flex h-full items-center rounded-lg px-3 text-sm font-medium text-gray-950" style={{ width: `${width}%`, backgroundColor: series[index % series.length] }}>
                                <span className="rounded bg-white/90 px-1.5 text-gray-950">{item.value}</span>
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
