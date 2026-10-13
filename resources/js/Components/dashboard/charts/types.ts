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
