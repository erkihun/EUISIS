import { useMemo } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { TrendAreaChart } from './charts/SimpleCharts';
import type { LabelValueDatum } from './charts/types';

export interface TrendSpec {
    key: string;
    title: string;
    /** Days with activity only; missing days are filled with zeros here. */
    data: LabelValueDatum[] | undefined;
}

interface Props {
    trends: TrendSpec[];
    dateFrom: string;
    dateTo: string;
}

/*
 * Columns by panel count and card width. Three panels go two-up first, with
 * the third spanning the row so it never leaves a hole, then three-up.
 */
const columns: Record<number, string> = {
    1: '',
    2: '@lg:grid-cols-2',
    3: '@lg:grid-cols-2 @lg:*:last:col-span-2 @2xl:grid-cols-3 @2xl:*:last:col-span-1',
};

const fill = (template: string, values: Record<string, string>) =>
    Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value), template);

/** Every ISO day from `from` to `to`, inclusive (UTC arithmetic, so no DST skips). */
function daysBetween(from: string, to: string): string[] {
    const start = Date.parse(`${from}T00:00:00Z`);
    const end = Date.parse(`${to}T00:00:00Z`);
    if (Number.isNaN(start) || Number.isNaN(end) || end < start) return [];
    const days: string[] = [];
    for (let time = start; time <= end && days.length < 400; time += 86_400_000) {
        days.push(new Date(time).toISOString().slice(0, 10));
    }

    return days;
}

/**
 * The period at a glance: one small multiple per measure, each on its own
 * scale. Registrations, verifications and transactions differ by orders of
 * magnitude, so one chart with two axes would invent a relationship; side by
 * side, each shape reads honestly. Columns follow the card's own width
 * (container queries), not the window.
 */
export default function ActivityTrends({ trends, dateFrom, dateTo }: Props) {
    const { t, locale } = useLocale();
    const { number } = useDisplayFormat();
    const calendar = useCalendarSystem();
    const formatDate = (iso: string) => formatDateDisplay(iso, calendar, locale) || iso;
    const days = useMemo(() => daysBetween(dateFrom, dateTo), [dateFrom, dateTo]);
    const plotted = days.length > 1;

    return (
        <div className="@container">
            <p className="mb-4 text-xs text-(--app-muted-foreground)">
                {fill(t('dashboard.overviewCharts.period'), { from: formatDate(dateFrom), to: formatDate(dateTo) })}
            </p>
            <div className={`grid gap-x-6 gap-y-6 ${columns[trends.length] ?? columns[3]}`}>
                {trends.map((trend) => {
                    const byDay = new Map((trend.data ?? []).map((point) => [point.label.slice(0, 10), point.value]));
                    const series = days.map((day) => ({ label: day, value: byDay.get(day) ?? 0 }));
                    const total = series.reduce((sum, point) => sum + point.value, 0);
                    const peak = series.reduce((best, point) => (point.value > best.value ? point : best), { label: '', value: 0 });
                    const summary = peak.value > 0
                        ? fill(t('dashboard.overviewCharts.trendSummary'), { title: trend.title, total: number(total), date: formatDate(peak.label), count: number(peak.value) })
                        : undefined;

                    return (
                        <section key={trend.key} aria-label={trend.title} className="min-w-0">
                            <h3 className="text-xs font-medium text-(--app-muted-foreground)">{trend.title}</h3>
                            <p className="mt-1 text-2xl font-semibold text-(--app-foreground)">{number(total)}</p>
                            {total === 0 ? (
                                <p className="mt-2 text-sm text-(--app-muted-foreground)">{t('dashboard.overviewCharts.noActivity')}</p>
                            ) : (
                                <>
                                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                        {fill(t('dashboard.overviewCharts.busiestDay'), { date: formatDate(peak.label), count: number(peak.value) })}
                                    </p>
                                    {plotted && (
                                        <div className="mt-3 w-full">
                                            <TrendAreaChart data={series} name={trend.title} formatDate={formatDate} formatValue={number} />
                                            <div className="flex justify-between gap-2 ps-8.5 text-[11px] text-(--app-muted-foreground)" aria-hidden="true">
                                                <span>{formatDate(days[0])}</span>
                                                <span>{formatDate(days[days.length - 1])}</span>
                                            </div>
                                        </div>
                                    )}
                                    {summary && <p className="sr-only">{summary}</p>}
                                </>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
