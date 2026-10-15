// The Situation Room chart bodies. The only module of the room that imports the
// chart library; loaded after first paint (see ./SituationChart.tsx).
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { SrChartImplProps } from './types';

const tooltipStyle = {
    contentStyle: { background: 'var(--app-surface)', borderColor: 'var(--app-border)', color: 'var(--app-foreground)', borderRadius: 8 },
    labelStyle: { color: 'var(--app-foreground)' },
    itemStyle: { color: 'var(--app-foreground)' },
};

/** Height that fits every row of a horizontal chart without squeezing labels. */
function chartHeight(type: SrChartImplProps['type'], rows: number, wall = false): number {
    const base = wall ? 360 : 260;
    if (type === 'hbar' || type === 'stacked') return Math.max(base * 0.75, rows * (wall ? 44 : 32) + 48);
    return base;
}

export default function SituationChartImpl({ type, rows, series, unit, suppressedLabel, colors, wall = false }: SrChartImplProps) {
    const fontSize = wall ? 16 : 12;
    const height = chartHeight(type, rows.length, wall);
    const tick = { fontSize, fill: colors.axisText };
    const format = (value: unknown) => (value === null || value === undefined ? suppressedLabel : `${Number(value).toLocaleString()}${unit && unit !== 'days' ? ` ${unit}` : ''}`);
    const seriesName = (key: unknown) => series.find((s) => s.key === key)?.label ?? String(key);
    const color = (index: number) => colors.series[index % colors.series.length];

    if (type === 'donut') {
        const data = rows.map((row) => ({ name: row.label, value: Number(row[series[0]?.key ?? ''] ?? 0) })).filter((d) => d.value > 0);
        return (
            <ResponsiveContainer width="100%" height={height}>
                <PieChart>
                    <Pie data={data} dataKey="value" nameKey="name" innerRadius="55%" outerRadius="80%" paddingAngle={1} isAnimationActive={false}>
                        {data.map((d, index) => <Cell key={d.name} fill={color(index)} />)}
                    </Pie>
                    <Tooltip {...tooltipStyle} formatter={(value) => format(value)} />
                    <Legend wrapperStyle={{ fontSize, color: colors.axisText }} />
                </PieChart>
            </ResponsiveContainer>
        );
    }

    if (type === 'line') {
        return (
            <ResponsiveContainer width="100%" height={height}>
                <LineChart data={rows} margin={{ top: 8, right: 12, bottom: 4, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                    <XAxis dataKey="label" tick={tick} minTickGap={16} />
                    <YAxis allowDecimals={false} tick={tick} width={wall ? 56 : 40} />
                    <Tooltip {...tooltipStyle} formatter={(value, name) => [format(value), seriesName(name)]} />
                    {series.length > 1 && <Legend formatter={seriesName} wrapperStyle={{ fontSize, color: colors.axisText }} />}
                    {series.map((s, index) => (
                        <Line key={s.key} type="monotone" dataKey={s.key} stroke={color(index)} strokeWidth={wall ? 3 : 2} dot={false} isAnimationActive={false} />
                    ))}
                </LineChart>
            </ResponsiveContainer>
        );
    }

    const horizontal = type === 'hbar' || type === 'stacked';
    const stacked = type === 'stacked';

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={rows} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ top: 8, right: 16, bottom: 4, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} horizontal={!horizontal} vertical={horizontal} />
                {horizontal ? (
                    <>
                        <XAxis type="number" allowDecimals={false} tick={tick} />
                        <YAxis type="category" dataKey="label" tick={tick} width={wall ? 220 : 150} interval={0} />
                    </>
                ) : (
                    <>
                        <XAxis dataKey="label" tick={tick} interval={0} angle={rows.length > 6 ? -30 : 0} textAnchor={rows.length > 6 ? 'end' : 'middle'} height={rows.length > 6 ? 64 : 30} />
                        <YAxis allowDecimals={false} tick={tick} width={wall ? 56 : 40} />
                    </>
                )}
                <Tooltip {...tooltipStyle} formatter={(value, name) => [format(value), seriesName(name)]} />
                {series.length > 1 && <Legend formatter={seriesName} wrapperStyle={{ fontSize, color: colors.axisText }} />}
                {series.map((s, index) => (
                    <Bar key={s.key} dataKey={s.key} stackId={stacked ? 'all' : undefined} fill={color(index)} radius={stacked ? 0 : (horizontal ? [0, 2, 2, 0] : [2, 2, 0, 0])} isAnimationActive={false}>
                        {series.length === 1 && !horizontal && rows.map((row, i) => <Cell key={row.label} fill={color(i)} />)}
                    </Bar>
                ))}
            </BarChart>
        </ResponsiveContainer>
    );
}
