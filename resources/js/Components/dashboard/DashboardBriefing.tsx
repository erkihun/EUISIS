import { Link, usePage } from '@inertiajs/react';
import { Layers } from '@/Components/Icons';
import { buttonClassName } from '@/Components/ui';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { AlertItem, QueueItem } from './AttentionPanel';
import type { KpiItem } from '@/Pages/Dashboard/Index';

type Signal = { key: string; tone: 'critical' | 'warning' | 'positive' | 'neutral'; text: string; href?: string };

const toneDot: Record<Signal['tone'], string> = {
    critical: 'bg-red-600',
    warning: 'bg-amber-500',
    positive: 'bg-emerald-600',
    neutral: 'bg-(--app-muted-foreground)',
};

/**
 * One line that says what today is about, before any figure: the two most
 * urgent items waiting on a person, plus the headline figure that moved. All
 * of it is derived from data the page already loads; nothing is computed here
 * that a tile below does not also show.
 */
export default function DashboardBriefing({ alerts, queues, kpis }: { alerts: AlertItem[]; queues: QueueItem[]; kpis: KpiItem[] }) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();
    const permissions = usePage<PageProps>().props.auth?.permissions ?? [];

    const waiting: Signal[] = [
        ...alerts.filter((a) => a.count > 0).map((a) => ({
            key: `alert-${a.key}`, tone: a.severity === 'critical' ? 'critical' as const : 'warning' as const,
            text: `${number(a.count)} ${t(a.titleKey)}`, href: a.href, rank: a.severity === 'critical' ? 0 : 1, count: a.count,
        })),
        ...queues.filter((q) => q.count > 0).map((q) => ({
            key: `queue-${q.key}`, tone: q.tone === 'warning' ? 'warning' as const : 'neutral' as const,
            text: `${number(q.count)} ${t(q.labelKey)}`, href: q.href, rank: q.tone === 'warning' ? 1 : 2, count: q.count,
        })),
    ].sort((a, b) => a.rank - b.rank || b.count - a.count).slice(0, 2);

    const moved = kpis.find((k) => k.trend !== null && k.trend !== undefined && k.trend !== 0 && k.trendDirection !== 'flat');
    const movement: Signal[] = moved
        ? [{
            key: `kpi-${moved.key}`, tone: 'positive',
            text: `${t(moved.labelKey)} ${moved.trend! > 0 ? '+' : ''}${number(moved.trend!)}${moved.comparisonLabelKey ? ` ${t(moved.comparisonLabelKey)}` : ''}`,
            href: moved.href ?? undefined,
        }]
        : [];

    const signals = [...waiting, ...movement];
    const canIntelligence = permissions.includes('intelligence.view');

    return (
        <section aria-label={t('dashboard.briefing.title')} className="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-panel border border-(--app-border) bg-(--app-surface) px-4 py-3">
            <span className="inline-flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                <Layers className="h-4 w-4 text-primary" aria-hidden="true" />
                {t('dashboard.briefing.title')}
            </span>
            {signals.length === 0 ? (
                <p className="m-0 flex-1 text-sm text-(--app-muted-foreground)">{t('dashboard.briefing.allClear')}</p>
            ) : (
                <ul className="m-0 flex flex-1 list-none flex-wrap gap-x-6 gap-y-2 p-0 text-sm">
                    {signals.map((s) => (
                        <li key={s.key} className="flex items-center gap-2">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${toneDot[s.tone]}`} aria-hidden="true" />
                            {s.href ? <Link href={s.href} className="text-(--app-foreground) hover:text-primary hover:underline">{s.text}</Link> : <span className="text-(--app-foreground)">{s.text}</span>}
                        </li>
                    ))}
                </ul>
            )}
            {canIntelligence && (
                <Link href={route('intelligence.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                    {t('dashboard.briefing.openIntelligence')}
                </Link>
            )}
        </section>
    );
}
