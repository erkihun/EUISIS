import type { ChartColors } from '@/hooks/useChartColors';
import type { SrChartType } from '@/types/situationRoom';

/** One chart row: the resolved category label and one value per series key. */
export type SrChartRow = { label: string } & Record<string, number | string | null>;

export interface SrChartSeries {
    key: string;
    label: string;
}

export interface SrChartProps {
    type: SrChartType;
    rows: SrChartRow[];
    series: SrChartSeries[];
    unit: string | null;
    /** Text for a value withheld by the small-group rule. */
    suppressedLabel: string;
    /** Taller, larger type for a wall display. */
    wall?: boolean;
    /** `diverging`: the series are the ordered points of a scale (dissatisfied → satisfied), not unrelated groups. */
    palette?: SrChartPalette;
}

export type SrChartPalette = 'diverging';

export type SrChartImplProps = SrChartProps & { colors: ChartColors };
