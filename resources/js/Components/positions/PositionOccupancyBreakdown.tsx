import { useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, LabelList, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useChartColors } from '@/hooks/useChartColors';
import { useLocale } from '@/hooks/useLocale';

export type BreakdownSlice = {
    key: string;
    label_en: string | null;
    label_am: string | null;
    total: number;
    filled: number;
    vacant: number;
};

export type Breakdowns = {
    grouping: 'organization' | 'unit';
    by_group: BreakdownSlice[];
    by_grade_level: BreakdownSlice[];
    by_job_family: BreakdownSlice[];
};

type Props = {
    breakdowns: Breakdowns;
};

type TabKey = 'group' | 'grade_level' | 'job_family';

/** Keys the backend uses for the rolled-up tail and for rows missing the grouping value. */
const OTHER_KEY = '__other__';
const UNASSIGNED_KEY = '__unassigned__';

type ChartRow = {
    label: string;
    shortLabel: string;
    filled: number;
    vacant: number;
    total: number;
    filledPercent: number;
    vacantPercent: number;
};

type PercentLabelProps = {
    x?: number | string;
    y?: number | string;
    width?: number | string;
    height?: number | string;
    value?: number | string;
};

/**
 * Percentage centred inside its stack segment. Segments narrower than the text
 * are left blank rather than spilling the label over the neighbouring bar.
 */
function renderPercentLabel(props: unknown) {
    const { x, y, width, height, value } = props as PercentLabelProps;
    const barWidth = Number(width ?? 0);
    const percent = Number(value ?? 0);

    if (!Number.isFinite(barWidth) || barWidth < 30 || percent <= 0) {
        return null;
    }

    return (
        <text
            x={Number(x ?? 0) + barWidth / 2}
            y={Number(y ?? 0) + Number(height ?? 0) / 2}
            fill="#ffffff"
            fontSize={11}
            fontWeight={600}
            textAnchor="middle"
            dominantBaseline="central"
        >
            {percent}%
        </text>
    );
}

export default function PositionOccupancyBreakdown({ breakdowns }: Props) {
    const { locale, t } = useLocale();
    const { series } = useChartColors();
    const [tab, setTab] = useState<TabKey>('group');
    const useAmharic = locale === 'am';

    const tabs: { key: TabKey; label: string; data: BreakdownSlice[] }[] = [
        {
            key: 'group',
            label: breakdowns.grouping === 'unit' ? t('positions.organizationUnit') : t('positions.organization'),
            data: breakdowns.by_group,
        },
        { key: 'grade_level', label: t('positions.gradeLevel'), data: breakdowns.by_grade_level },
        { key: 'job_family', label: t('positions.jobFamily'), data: breakdowns.by_job_family },
    ];

    const active = tabs.find((entry) => entry.key === tab) ?? tabs[0];

    const chartData = useMemo(() => active.data.map((slice) => {
        const named = (useAmharic ? slice.label_am : slice.label_en) ?? slice.label_en;
        const label = slice.key === OTHER_KEY
            ? t('positions.otherGroups')
            : named ?? t('positions.notSpecified');

        // Percentages are relative to the row's own total, so each bar reads
        // as its own occupancy split rather than a share of the grand total.
        const share = (value: number) => (slice.total > 0 ? Math.round((value / slice.total) * 100) : 0);

        return {
            label,
            // Long organization/unit names would otherwise squeeze the plot area.
            shortLabel: label.length > 22 ? `${label.slice(0, 21)}…` : label,
            filled: slice.filled,
            vacant: slice.vacant,
            total: slice.total,
            filledPercent: share(slice.filled),
            vacantPercent: share(slice.vacant),
        };
    }), [active.data, useAmharic, t]);

    const filledColor = series[2] ?? '#16a34a';
    const vacantColor = series[1] ?? '#d12908';

    return (
        <section className="rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('positions.occupancyBreakdown')}
                </h2>
                <div className="flex flex-wrap gap-1 rounded-lg border border-gray-200 p-1 dark:border-slate-800">
                    {tabs.map((entry) => (
                        <button
                            key={entry.key}
                            type="button"
                            onClick={() => setTab(entry.key)}
                            className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                                entry.key === active.key
                                    ? 'bg-[color:var(--color-primary)] text-white'
                                    : 'text-gray-600 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-800'
                            }`}
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
            </div>

            {chartData.length === 0 ? (
                <div className="py-12 text-center text-sm text-gray-500 dark:text-slate-400">
                    {t('positions.noPositionStatusFound')}
                </div>
            ) : (
                <div className="min-w-0" style={{ height: Math.max(220, chartData.length * 42 + 60) }}>
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={chartData} layout="vertical" margin={{ top: 4, right: 16, bottom: 4, left: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-gray-200 dark:stroke-slate-800" />
                            <XAxis type="number" allowDecimals={false} fontSize={11} />
                            <YAxis type="category" dataKey="shortLabel" width={150} fontSize={11} interval={0} />
                            <Tooltip
                                cursor={{ fillOpacity: 0.08 }}
                                labelFormatter={(_label, payload) => payload?.[0]?.payload?.label ?? ''}
                                formatter={(value, name, entry) => {
                                    const row = entry?.payload as ChartRow | undefined;
                                    const percent = name === t('positions.vacant') ? row?.vacantPercent : row?.filledPercent;

                                    return [`${Number(value ?? 0).toLocaleString()} (${percent ?? 0}%)`, name];
                                }}
                            />
                            <Legend />
                            <Bar dataKey="filled" stackId="occupancy" name={t('positions.filled')} fill={filledColor} radius={[0, 0, 0, 0]}>
                                <LabelList dataKey="filledPercent" content={renderPercentLabel} />
                            </Bar>
                            <Bar dataKey="vacant" stackId="occupancy" name={t('positions.vacant')} fill={vacantColor} radius={[0, 4, 4, 0]}>
                                <LabelList dataKey="vacantPercent" content={renderPercentLabel} />
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </section>
    );
}
