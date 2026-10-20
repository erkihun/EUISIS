// Loads the chart body after first paint, behind a placeholder of the same
// height so nothing moves when it arrives (the dashboard's pattern).
import { lazy, Suspense } from 'react';
import { ChartPlaceholder } from '@/Components/dashboard/charts/SimpleCharts';
import { useChartColors } from '@/hooks/useChartColors';
import type { SrChartProps } from './types';

const Impl = lazy(() => import('./SituationChartImpl'));

function placeholderHeight({ type, rows, wall }: SrChartProps): number {
    const base = wall ? 360 : 260;
    if (type === 'hbar' || type === 'stacked') return Math.max(base * 0.75, rows.length * (wall ? 44 : 32) + 48);
    return base;
}

export default function SituationChart(props: SrChartProps) {
    const colors = useChartColors();

    return (
        <Suspense fallback={<ChartPlaceholder height={placeholderHeight(props)} />}>
            <Impl {...props} colors={colors} />
        </Suspense>
    );
}
