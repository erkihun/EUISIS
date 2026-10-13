// The chart bodies. This module is the only one that imports the chart library,
// and it is loaded after the dashboard's first paint (see ./SimpleCharts.tsx).
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { SimpleBarChartProps, SimpleLineChartProps, WithColors } from './types';

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
