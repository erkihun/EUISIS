import WallLayout from '@/Layouts/WallLayout';
import { MultiSeriesLineChart, StackedBarChart } from '@/Components/dashboard/charts/SimpleCharts';
import type { CategoryRow, Institutions, Kpis, Trend } from '@/Components/situationRoom/sections';
import { TYPES, fmt, useDateLabel, useNamer, viewQuery, type Filters, type ScopeProps } from '@/Components/situationRoom/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

type Props = {
    scope: ScopeProps;
    filters: Filters;
    wall: { kpis: Kpis; trend: Trend; institutions: Institutions; categories: CategoryRow[]; resolution: Record<string, number>; generated_at: string };
    refreshSeconds: number;
};

/**
 * Wall mode: aggregate figures for a screen in a leadership office. No
 * person's name, no submission text, no controls beyond leaving. Reloads the
 * figures on a timer; the server caches them for the same interval.
 */
export default function SituationRoomWall({ scope, filters, wall, refreshSeconds }: Props) {
    const { t } = useLocale();
    const name = useNamer();
    const { date, dateTime } = useDateLabel();
    const query = viewQuery(scope, filters);
    const series = TYPES.map((type) => ({ key: type, label: t(`situationRoom.type_${type}`) }));

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['wall'] }), refreshSeconds * 1000);
        return () => window.clearInterval(timer);
    }, [refreshSeconds]);

    const kpis: { key: keyof Kpis['current']; label: string }[] = [
        { key: 'feedback', label: t('situationRoom.kpiFeedback') },
        { key: 'suggestion', label: t('situationRoom.kpiSuggestions') },
        { key: 'complaint', label: t('situationRoom.kpiComplaints') },
        { key: 'unresolved', label: t('situationRoom.kpiUnresolved') },
        { key: 'resolved', label: t('situationRoom.kpiResolved') },
        { key: 'unrouted', label: t('situationRoom.kpiUnrouted') },
    ];
    const institutions = [
        ...wall.institutions.rows.slice(0, 10).map((row) => ({ label: name(row), feedback: row.feedback, suggestion: row.suggestion, complaint: row.complaint })),
    ].filter((row) => row.feedback + row.suggestion + row.complaint > 0);
    const categoryMax = Math.max(1, ...wall.categories.map((row) => row.total));

    return (
        <WallLayout>
            <Head title={`${t('situationRoom.wallTitle')} — ${scope.node ? name(scope.node) : t('situationRoom.cityWide')}`} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="text-lg text-[color:var(--app-muted-foreground)]">{t('situationRoom.wallTitle')}</p>
                    <h1 className="text-3xl font-bold xl:text-4xl">{scope.node ? name(scope.node) : t('situationRoom.cityWide')}</h1>
                    <p className="mt-1 text-lg">{fmt(t('situationRoom.periodRange'), { from: date(filters.from), to: date(filters.to) })}</p>
                </div>
                <div className="text-end">
                    <p className="text-lg">{t('situationRoom.lastUpdated')}: <time dateTime={wall.generated_at}>{dateTime(wall.generated_at)}</time></p>
                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{fmt(t('situationRoom.wallRefreshNote'), { minutes: Math.round(refreshSeconds / 60) })} · {t('situationRoom.wallAggregateNote')}</p>
                    <Link href={route(scope.node ? 'situation-room.organization' : 'situation-room.index', query)} className="mt-1 inline-block text-sm underline underline-offset-4">{t('situationRoom.exitWall')}</Link>
                </div>
            </header>

            <section aria-label={t('situationRoom.title')} className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                {kpis.map((kpi) => (
                    <div key={kpi.key} className="rounded-[var(--radius-card)] border-2 border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] p-5">
                        <p className="text-lg text-[color:var(--app-muted-foreground)]">{kpi.label}</p>
                        <p className="mt-2 text-5xl font-bold tabular-nums">{Number(wall.kpis.current[kpi.key] ?? 0).toLocaleString()}</p>
                    </div>
                ))}
            </section>

            <div className="grid flex-1 gap-6 xl:grid-cols-2">
                <section className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5" aria-labelledby="wall-trend">
                    <h2 id="wall-trend" className="text-xl font-semibold">{t('situationRoom.trendTitle')}</h2>
                    <p className="sr-only">{fmt(t('situationRoom.trendSummary'), wall.kpis.current)}</p>
                    <div className="mt-3" aria-hidden="true"><MultiSeriesLineChart data={wall.trend.points} xKey="bucket" series={series} xLabel={(value) => date(value)} height={320} emptyTitle={t('situationRoom.summary_no_submissions')} /></div>
                </section>
                <section className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5" aria-labelledby="wall-institutions">
                    <h2 id="wall-institutions" className="text-xl font-semibold">{t('situationRoom.institutionsTitle')}</h2>
                    <div className="mt-3">
                        {institutions.length === 0 ? <p className="text-lg text-[color:var(--app-muted-foreground)]">{t('situationRoom.noChildren')}</p>
                            : <StackedBarChart data={institutions} labelKey="label" series={series} />}
                    </div>
                </section>
                <section className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5" aria-labelledby="wall-categories">
                    <h2 id="wall-categories" className="text-xl font-semibold">{t('situationRoom.categoriesTitle')}</h2>
                    <ul className="mt-3 space-y-3">
                        {wall.categories.map((row) => (
                            <li key={row.id ?? 'none'}>
                                <div className="flex items-baseline justify-between gap-3 text-lg"><span className="truncate">{row.uncategorized ? t('situationRoom.uncategorized') : name(row)}</span><span className="font-semibold tabular-nums">{row.total.toLocaleString()}</span></div>
                                <div className="mt-1 h-2 rounded-full bg-[color:var(--app-surface-muted)]" aria-hidden="true"><div className="h-2 rounded-full bg-[color:var(--color-primary)]" style={{ width: `${Math.max(2, Math.round((row.total / categoryMax) * 100))}%` }} /></div>
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5" aria-labelledby="wall-status">
                    <h2 id="wall-status" className="text-xl font-semibold">{t('situationRoom.resolutionTitle')}</h2>
                    <dl className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {Object.entries(wall.resolution).map(([status, value]) => (
                            <div key={status}>
                                <dt className="text-lg text-[color:var(--app-muted-foreground)]">{t(`situationRoom.status_${status}`)}</dt>
                                <dd className="text-3xl font-bold tabular-nums">{value.toLocaleString()}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </div>
        </WallLayout>
    );
}
