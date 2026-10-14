// Dashboard bar and line charts that load the chart library after the first paint.
//
// The empty state and the card around the chart render at once from the page
// chunk; only the plot itself waits for the (300 KB) chart library, behind a
// placeholder of the same height so nothing moves when it arrives.
import { lazy, Suspense } from 'react';
import EmptyDashboardState from '@/Components/dashboard/EmptyDashboardState';
import { useChartColors } from '@/hooks/useChartColors';
import type { MultiSeriesLineChartProps, SimpleBarChartProps, SimpleLineChartProps, StackedBarChartProps } from './types';

const BarChartImpl = lazy(() => import('./SimpleChartsImpl').then((m) => ({ default: m.SimpleBarChartImpl })));
const LineChartImpl = lazy(() => import('./SimpleChartsImpl').then((m) => ({ default: m.SimpleLineChartImpl })));

export function ChartPlaceholder({ height }: { height: number }) {
    return <div aria-hidden="true" className="w-full animate-pulse rounded-card bg-[color:var(--app-surface-muted)]" style={{ height }} />;
}

export function SimpleBarChart(props: SimpleBarChartProps) {
    const colors = useChartColors();

    if (props.data.length === 0) {
        return <EmptyDashboardState compact title={props.emptyTitle} />;
    }

    return (
        <Suspense fallback={<ChartPlaceholder height={240} />}>
            <BarChartImpl {...props} colors={colors} />
        </Suspense>
    );
}

export function SimpleLineChart(props: SimpleLineChartProps) {
    const colors = useChartColors();

    if (props.data.length === 0) {
        return <EmptyDashboardState compact title={props.emptyTitle} />;
    }

    return (
        <Suspense fallback={<ChartPlaceholder height={240} />}>
            <LineChartImpl {...props} colors={colors} />
        </Suspense>
    );
}

const MultiSeriesLineChartImpl = lazy(() => import('./SimpleChartsImpl').then((m) => ({ default: m.MultiSeriesLineChartImpl })));
const StackedBarChartImpl = lazy(() => import('./SimpleChartsImpl').then((m) => ({ default: m.StackedBarChartImpl })));

export function MultiSeriesLineChart(props: MultiSeriesLineChartProps) {
    const colors = useChartColors();

    if (props.data.length === 0) {
        return <EmptyDashboardState compact title={props.emptyTitle} />;
    }

    return (
        <Suspense fallback={<ChartPlaceholder height={props.height ?? 260} />}>
            <MultiSeriesLineChartImpl {...props} colors={colors} />
        </Suspense>
    );
}

export function StackedBarChart(props: StackedBarChartProps) {
    const colors = useChartColors();

    if (props.data.length === 0) {
        return <EmptyDashboardState compact title={props.emptyTitle} />;
    }

    return (
        <Suspense fallback={<ChartPlaceholder height={Math.max(160, props.data.length * 34 + 48)} />}>
            <StackedBarChartImpl {...props} colors={colors} />
        </Suspense>
    );
}
