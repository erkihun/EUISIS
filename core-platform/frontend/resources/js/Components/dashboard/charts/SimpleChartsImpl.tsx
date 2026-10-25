// The chart bodies. This module is the only one that imports the chart library,
// and it is loaded after the dashboard's first paint (see ./SimpleCharts.tsx).
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { MultiSeriesLineChartProps, SimpleBarChartProps, SimpleLineChartProps, StackedBarChartProps, TrendChartProps, WithColors } from './types';

const tooltipStyle = {
    contentStyle: { background: 'var(--app-surface)', borderColor: 'var(--app-border)', color: 'var(--app-foreground)', borderRadius: 8 },
    labelStyle: { color: 'var(--app-foreground)' },
    itemStyle: { color: 'var(--app-foreground)' },
};

export function SimpleBarChartImpl({ data, labelFor, colors }: WithColors<SimpleBarChartProps>) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                <XAxis dataKey="key" tickFormatter={labelFor} tick={{ fontSize: 12, fill: colors.axisText }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: colors.axisText }} width={36} />
                <Tooltip
                    {...tooltipStyle}
                    formatter={(value, name) => [Number(value ?? 0), labelFor(String(name))]}
                    labelFormatter={(label) => labelFor(String(label))}
                />
                {/* Square-ish caps: a 8px radius on a 20px bar reads as a pill. */}
                <Bar dataKey="value" fill={colors.primary} radius={[2, 2, 0, 0]}>
                    {data.map((item, index) => <Cell key={item.key} fill={colors.series[index % colors.series.length]} />)}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

export function SimpleLineChartImpl({ data, colors }: WithColors<SimpleLineChartProps>) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 12, fill: colors.axisText }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: colors.axisText }} width={36} />
                <Tooltip {...tooltipStyle} />
                <Line type="monotone" dataKey="value" stroke={colors.primary} strokeWidth={2} dot={false} activeDot={{ fill: colors.accent, stroke: colors.accent }} />
            </LineChart>
        </ResponsiveContainer>
    );
}

export function MultiSeriesLineChartImpl({ data, xKey, series, xLabel, colors, height = 260 }: WithColors<MultiSeriesLineChartProps>) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                <XAxis dataKey={xKey} tickFormatter={xLabel} tick={{ fontSize: 12, fill: colors.axisText }} minTickGap={16} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: colors.axisText }} width={40} />
                <Tooltip {...tooltipStyle} labelFormatter={(label) => (xLabel ? xLabel(String(label)) : String(label))} />
                <Legend wrapperStyle={{ fontSize: 12, color: colors.axisText }} />
                {series.map((s, index) => (
                    <Line key={s.key} type="monotone" dataKey={s.key} name={s.label} stroke={colors.series[index % colors.series.length]} strokeWidth={2} dot={false} strokeDasharray={index === 2 ? '6 3' : undefined} isAnimationActive={false} />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
}

export function StackedBarChartImpl({ data, labelKey, series, colors }: WithColors<StackedBarChartProps>) {
    // Horizontal bars so long institution names stay readable; height follows the row count.
    const height = Math.max(160, data.length * 34 + 48);

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data} layout="vertical" margin={{ left: 8, right: 16 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} horizontal={false} />
                <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12, fill: colors.axisText }} />
                <YAxis type="category" dataKey={labelKey} width={160} tick={{ fontSize: 12, fill: colors.axisText }} tickFormatter={(value: string) => (value.length > 24 ? `${value.slice(0, 23)}…` : value)} />
                <Tooltip {...tooltipStyle} />
                <Legend wrapperStyle={{ fontSize: 12, color: colors.axisText }} />
                {series.map((s, index) => (
                    <Bar key={s.key} dataKey={s.key} name={s.label} stackId="types" fill={colors.series[index % colors.series.length]} isAnimationActive={false} />
                ))}
            </BarChart>
        </ResponsiveContainer>
    );
}

/**
 * A small-multiple daily trend: one measure, its own scale, a 2px line over a
 * 10% wash. The x-axis is left off (the card states the period once) and the
 * crosshair tooltip names the day; gridlines are solid hairlines.
 */
export function TrendAreaChartImpl({ data, name, formatDate, formatValue, colors, height = 112 }: WithColors<TrendChartProps>) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <AreaChart data={data} margin={{ top: 6, right: 6, bottom: 6, left: 0 }}>
                <CartesianGrid stroke={colors.grid} vertical={false} />
                <XAxis dataKey="label" hide />
                <YAxis
                    allowDecimals={false}
                    width={34}
                    tickCount={3}
                    interval={0}
                    axisLine={false}
                    tickLine={false}
                    tick={{ fontSize: 11, fill: colors.axisText }}
                    tickFormatter={(value: number) => formatValue(value)}
                />
                <Tooltip
                    {...tooltipStyle}
                    cursor={{ stroke: colors.axisText, strokeWidth: 1 }}
                    separator=": "
                    labelFormatter={(label) => formatDate(String(label))}
                    formatter={(value) => [formatValue(Number(value ?? 0)), name]}
                />
                <Area
                    type="linear"
                    dataKey="value"
                    stroke={colors.primary}
                    strokeWidth={2}
                    fill={colors.primary}
                    fillOpacity={0.1}
                    dot={false}
                    activeDot={{ r: 4, fill: colors.primary, stroke: 'var(--app-surface)', strokeWidth: 2 }}
                    isAnimationActive={false}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
