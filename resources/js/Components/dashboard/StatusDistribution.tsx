import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import EmptyDashboardState from './EmptyDashboardState';
import { useChartColors } from '@/hooks/useChartColors';

interface Item {
    key: string;
    value: number;
}

interface Props {
    data: Item[];
    labelFor: (key: string) => string;
}

export default function StatusDistribution({ data, labelFor }: Props) {
    const { series: colors } = useChartColors();

    if (data.length === 0) {
        return <EmptyDashboardState compact />;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
            <div className="h-64 min-w-0">
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
