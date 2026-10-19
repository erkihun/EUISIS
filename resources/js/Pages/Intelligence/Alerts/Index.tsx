import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { SeverityBadge } from '@/Components/intelligence/Badges';
import { FilterChips } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Button, Card, ListPagination, badgeClassName, type Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabel, SrLabels } from '@/types/situationRoom';
import type { InsightSeverity } from '@/types/intelligence';
import type { Paginated } from '@/types/intelligenceSystem';

type AlertRow = {
    id: string; type: string; domain: 'business' | 'technical'; severity: InsightSeverity; status: 'open' | 'acknowledged' | 'resolved';
    title: string; params: Record<string, string | number | null>; evidence: Array<{ label: SrLabel | string; value: string | number | null }>;
    recommendation: string | null; href: string | null; area: string | null; occurrences: number; first_detected_at: string; last_detected_at: string;
    acknowledged_by: string | null; acknowledged_at: string | null; resolved_by: string | null; resolved_at: string | null; can_manage: boolean;
};

type Props = {
    labels: SrLabels;
    filters: { status: string; domain: string };
    options: { statuses: string[]; domains: string[] };
    counts: { business: number; technical: number };
    alerts: Paginated<AlertRow>;
};

const STATUS_TONE: Record<AlertRow['status'], Tone> = { open: 'danger', acknowledged: 'warning', resolved: 'success' };

/** Deduplicated alerts from both domains; people acknowledge and resolve, the engine only opens and re-resolves. */
export default function AlertsIndex({ filters, options, counts, alerts }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr, label } = useSituationLabels();
    const title = t('intelligence.areas.alerts.title');
    const build = (patch: Partial<typeof filters>) => route('intelligence.alerts.index', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v !== '')));
    const act = (alert: AlertRow, action: 'acknowledge' | 'resolve' | 'reopen') => router.patch(route('intelligence.alerts.update', alert.id), { action }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligence.areas.alerts.description')} backHref={route('intelligence.index')} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="space-y-5">
                <div className="space-y-2">
                    <FilterChips label={t('intelligence.incidents.status')} value={filters.status}
                        options={['active', ...options.statuses].map((key) => ({ key, label: key === 'active' ? t('intelligence.incidents.active') : sr(`intelligence.alert_status.${key}`) }))} build={(key) => build({ status: key })} />
                    <FilterChips label={t('intelligence.alertsPage.domain')} value={filters.domain}
                        options={[{ key: '', label: t('intelligence.incidents.all') }, ...options.domains.map((key) => ({ key, label: `${sr(`intelligence.domain.${key}`)} (${counts[key as 'business' | 'technical']})` }))]} build={(key) => build({ domain: key })} />
                </div>

                {alerts.data.length === 0 ? (
                    <Card><p className="text-sm text-(--app-muted-foreground)">{t('intelligence.alertsPage.none')}</p></Card>
                ) : (
                    <ul className="space-y-3">
                        {alerts.data.map((alert) => (
                            <li key={alert.id}>
                                <Card className="space-y-2">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                                                <span>{sr(`intelligence.domain.${alert.domain}`)}</span>
                                                <span aria-hidden="true">·</span><span>{sr(`intelligence.alert_type.${alert.type}`)}</span>
                                                <span aria-hidden="true">·</span><span>{alert.occurrences}× </span>
                                                <span aria-hidden="true">·</span><LocalizedDateDisplay value={alert.last_detected_at} withTime />
                                            </div>
                                            <h2 className="mt-1 font-semibold text-(--app-foreground)">{sr(alert.title, alert.params as Record<string, string | number>)}</h2>
                                        </div>
                                        <span className="flex flex-wrap gap-1.5"><SeverityBadge severity={alert.severity} /><span className={badgeClassName(STATUS_TONE[alert.status])}>{sr(`intelligence.alert_status.${alert.status}`)}</span></span>
                                    </div>
                                    {alert.recommendation && <p className="rounded-control bg-(--app-surface-muted) px-3 py-2 text-sm"><span className="font-medium">{sr('intelligence.ui.recommendation')}: </span>{sr(alert.recommendation, alert.params as Record<string, string | number>)}</p>}
                                    {alert.evidence.length > 0 && (
                                        <dl className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-(--app-muted-foreground)">
                                            {alert.evidence.slice(0, 6).map((row, i) => (
                                                <div key={i} className="flex gap-1"><dt>{typeof row.label === 'string' ? sr(`intelligence.evidence.${row.label}`) : label(row.label)}:</dt><dd className="font-medium text-(--app-foreground)">{row.value === null ? '—' : String(row.value)}</dd></div>
                                            ))}
                                        </dl>
                                    )}
                                    <div className="flex flex-wrap items-center gap-2 pt-1 text-xs text-(--app-muted-foreground)">
                                        {alert.acknowledged_by && <span>{sr('intelligence.alert_status.acknowledged')} · {alert.acknowledged_by}</span>}
                                        {alert.resolved_by && <span>{sr('intelligence.alert_status.resolved')} · {alert.resolved_by}</span>}
                                        <span className="ml-auto flex flex-wrap gap-2">
                                            {alert.href && <Link href={alert.href} className="text-sm font-medium text-primary hover:underline">{sr('intelligence.ui.open_detail')}</Link>}
                                            {alert.can_manage && alert.status === 'open' && <Button size="sm" variant="outline" onClick={() => act(alert, 'acknowledge')}>{t('intelligence.alertsPage.acknowledge')}</Button>}
                                            {alert.can_manage && alert.status !== 'resolved' && <Button size="sm" variant="outline" onClick={() => act(alert, 'resolve')}>{t('intelligence.alertsPage.resolve')}</Button>}
                                            {alert.can_manage && alert.status === 'resolved' && <Button size="sm" variant="ghost" onClick={() => act(alert, 'reopen')}>{t('intelligence.alertsPage.reopen')}</Button>}
                                        </span>
                                    </div>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={alerts} bare />
            </div>
        </AuthenticatedLayout>
    );
}
