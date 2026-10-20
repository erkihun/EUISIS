import type { ChartColors } from '@/hooks/useChartColors';

export interface KeyValueDatum {
    key: string;
    value: number;
}

export interface LabelValueDatum {
    label: string;
    value: number;
}

export interface SimpleBarChartProps {
    data: KeyValueDatum[];
    labelFor: (key: string) => string;
    emptyTitle?: string;
}

export interface SimpleLineChartProps {
    data: LabelValueDatum[];
    emptyTitle?: string;
}

/** Props of the chart bodies; the wrapper resolves the palette so the body needs no hook. */
export type WithColors<T> = T & { colors: ChartColors };

/** One named series in a multi-series chart. */
export interface ChartSeries {
    key: string;
    label: string;
}

export interface MultiSeriesLineChartProps {
    /** One row per x position; each series reads its own numeric key. */
    data: Array<Record<string, string | number>>;
    xKey: string;
    series: ChartSeries[];
    xLabel?: (value: string) => string;
    emptyTitle?: string;
    height?: number;
}

export interface StackedBarChartProps {
    /** One row per category (drawn as a horizontal bar), stacked by series. */
    data: Array<Record<string, string | number>>;
    labelKey: string;
    series: ChartSeries[];
    emptyTitle?: string;
}

/** A compact daily trend, one measure per chart (small multiples, never a second axis). */
export interface TrendChartProps {
    /** One point per day of the period, gap-filled with zeros; `label` is an ISO date. */
    data: LabelValueDatum[];
    /** The measure's name, shown in the tooltip. */
    name: string;
    formatDate: (iso: string) => string;
    formatValue: (value: number) => string;
    height?: number;
}
