import { lazy, Suspense } from 'react';
import EmptyDashboardState from './EmptyDashboardState';
import { ChartPlaceholder } from './charts/SimpleCharts';
import { useChartColors } from '@/hooks/useChartColors';

// The legend renders from the page chunk straight away; the pie (and with it
// the chart library) arrives after the first paint into a same-size slot.
const StatusDistributionPie = lazy(() => import('./charts/StatusDistributionPie'));

interface Item {
    key: string;
    value: number;
}

interface Props {
    data: Item[];
    labelFor: (key: string) => string;
    /** Localised "no data" line; without it the card would show a blank gap. */
    emptyTitle?: string;
}

export default function StatusDistribution({ data, labelFor, emptyTitle }: Props) {
    const { series: colors } = useChartColors();

    if (data.length === 0) {
        return <EmptyDashboardState compact title={emptyTitle} />;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
            {/*
              * `w-full` is load-bearing, not decorative. Recharts'
              * ResponsiveContainer measures its parent; a grid child with only
              * `min-w-0` can collapse to its content's intrinsic width, and the
              * donut then drew at ~106px inside a 310px column on a 375px
              * viewport. An explicit width gives the container a definite basis
              * to measure against.
              */}
            <div className="h-64 w-full min-w-0">
                <Suspense fallback={<ChartPlaceholder height={256} />}>
                    <StatusDistributionPie data={data} colors={colors} labelFor={labelFor} />
                </Suspense>
            </div>
            <div className="divide-y divide-[color:var(--app-border)] self-center">
                {data.map((item, index) => (
                    <div key={item.key} className="flex items-center justify-between gap-3 py-2.5">
                        <div className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-sm" style={{ backgroundColor: colors[index % colors.length] }} />
                            <span className="text-sm text-[color:var(--app-foreground)]">{labelFor(item.key)}</span>
                        </div>
                        <span className="text-sm font-semibold text-[color:var(--app-foreground)]">{item.value}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
