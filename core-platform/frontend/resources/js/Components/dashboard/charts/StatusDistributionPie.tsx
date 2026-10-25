// The pie of StatusDistribution; the only part that needs the chart library.
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import type { KeyValueDatum } from './types';

interface Props {
    data: KeyValueDatum[];
    colors: string[];
    labelFor: (key: string) => string;
}

export default function StatusDistributionPie({ data, colors, labelFor }: Props) {
    return (
        <ResponsiveContainer width="100%" height={256}>
            <PieChart>
                <Pie data={data} dataKey="value" nameKey="key" innerRadius={55} outerRadius={88}>
                    {data.map((entry, index) => (
                        <Cell key={entry.key} fill={colors[index % colors.length]} />
                    ))}
                </Pie>
                <Tooltip
                    formatter={(value, name) => [Number(value ?? 0), labelFor(String(name))]}
                />
            </PieChart>
        </ResponsiveContainer>
    );
}
