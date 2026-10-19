import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { ActivityIcon, ClockIcon, NetworkIcon, Plus, SendIcon, ShieldCheck } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import { Button, Card, EmptyState, Table, TBody, THead, Td, Th, Tr } from '@euisis/ui';

type Kpis = Record<string, number | string | null>;

interface SubscriptionRow {
    id: string;
    name: string;
    application: string | null;
    application_code: string | null;
    host: string | null;
    event_type_count: number;
    status: string;
    health: string;
    consecutive_failures: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    last_failure_category: string | null;
}

interface DeliveryRow {
    id: string;
    event_id: string;
    event_type: string | null;
    status: string;
    attempts: number;
    last_http_status: number | null;
    last_error_category: string | null;
    next_attempt_at: string | null;
    subscription_id: string;
    subscription: string | null;
}

interface ConsumerRow {
    name: string;
    code: string;
    last_position: number;
    behind: number;
    last_read_at: string | null;
}

interface Props {
    kpis: Kpis;
    subscriptions: SubscriptionRow[];
    failedDeliveries: DeliveryRow[];
    consumers: ConsumerRow[];
    can: { manage: boolean; deliveries: boolean; replay: boolean; payload: boolean };
}

const HEALTH_TONE: Record<string, string> = { healthy: 'active', degraded: 'pending', failing: 'failed', unknown: 'inactive' };

/** Seconds as "45 s", "12 min", "3 h". */
function age(seconds: number): string {
    if (seconds < 120) return `${seconds} s`;
    if (seconds < 7200) return `${Math.round(seconds / 60)} min`;
    return `${Math.round(seconds / 3600)} h`;
}

export default function IntegrationsIndex({ kpis, subscriptions, failedDeliveries, consumers, can }: Props) {
    const { t } = useLocale();
    const unknown = t('integrations.kpi.unknown');
    const num = (value: number | string | null) => (value === null || value === undefined ? unknown : value);

    const actions = can.manage
        ? <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => router.visit(route('integrations.subscriptions.create'))}>{t('integrations.newSubscription')}</Button>
        : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('integrations.title')} description={t('integrations.description')} backHref={route('system-settings.index')} actions={actions} />}>
            <Head title={t('integrations.title')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-2 text-sm text-(--app-muted-foreground)">
                    <span className="font-medium text-(--app-foreground)">{t('integrations.switches')}:</span>
                    {(['events', 'feed', 'webhooks'] as const).map((key) => {
                        const value = kpis[key === 'events' ? 'events_enabled' : key === 'feed' ? 'change_feed_enabled' : 'webhooks_enabled'];
                        return (
                            <StatusBadge key={key} status={value === 'on' ? 'active' : 'inactive'}
                                label={`${t(`integrations.switch_${key}`)}: ${value === 'on' ? t('integrations.switch_on') : value === 'off' ? t('integrations.switch_off') : unknown}`} />
                        );
                    })}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppMetricCard label={t('integrations.kpi.pending')} value={num(kpis.pending_events)} variant="primary" icon={<ActivityIcon className="h-5 w-5" />} detail={t('integrations.kpi.pendingHint')} />
                    <AppMetricCard label={t('integrations.kpi.oldestPending')} value={kpis.oldest_pending_seconds === null ? unknown : age(Number(kpis.oldest_pending_seconds))} variant="accent" icon={<ClockIcon className="h-5 w-5" />} detail={t('integrations.kpi.oldestPendingHint')} />
                    <AppMetricCard label={t('integrations.kpi.deliveriesToday')} value={num(kpis.deliveries_today)} variant="success" icon={<SendIcon className="h-5 w-5" />} detail={t('integrations.kpi.deliveriesTodayHint')} />
                    <AppMetricCard label={t('integrations.kpi.dead')} value={num(kpis.dead_deliveries)} variant="danger" icon={<ShieldCheck className="h-5 w-5" />} detail={t('integrations.kpi.deadHint')} />
                    <AppMetricCard label={t('integrations.kpi.retrying')} value={num(kpis.retrying_deliveries)} variant="warning" icon={<ActivityIcon className="h-5 w-5" />} detail={t('integrations.kpi.retryingHint')} />
                    <AppMetricCard label={t('integrations.kpi.activeSubscriptions')} value={num(kpis.active_subscriptions)} variant="primary" icon={<NetworkIcon className="h-5 w-5" />} detail={t('integrations.kpi.activeSubscriptionsHint')} />
                    <AppMetricCard label={t('integrations.kpi.rejected')} value={num(kpis.rejected_events)} variant="danger" icon={<ShieldCheck className="h-5 w-5" />} detail={t('integrations.kpi.rejectedHint')} />
                    <AppMetricCard label={t('integrations.kpi.feedPosition')} value={num(kpis.feed_position)} variant="accent" icon={<NetworkIcon className="h-5 w-5" />}
                        detail={`${t('integrations.kpi.latency')}: ${kpis.avg_latency_ms_24h === null ? unknown : `${kpis.avg_latency_ms_24h} ms`}`} />
                </div>

                <Card className="overflow-hidden p-0">
                    <div className="border-b border-(--app-border) px-5 py-4">
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.subscriptions')}</h2>
                        <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{t('integrations.subscriptionsHint')}</p>
                    </div>
                    {subscriptions.length === 0 ? (
                        <EmptyState icon={<NetworkIcon className="h-8 w-8" />} title={t('integrations.noSubscriptions')} description={t('integrations.noSubscriptionsHint')} action={actions} />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('integrations.name')}</Th>
                                    <Th>{t('integrations.application')}</Th>
                                    <Th>{t('integrations.host')}</Th>
                                    <Th align="right">{t('integrations.eventTypes')}</Th>
                                    <Th>{t('common.status')}</Th>
                                    <Th>{t('integrations.health')}</Th>
                                    <Th>{t('integrations.lastSuccess')}</Th>
                                    <Th><span className="sr-only">{t('common.actions')}</span></Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {subscriptions.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><Link href={route('integrations.subscriptions.show', row.id)} className="font-medium text-(--app-foreground) hover:text-primary">{row.name}</Link></Td>
                                        <Td>{row.application}<div className="font-mono text-xs text-(--app-muted-foreground)">{row.application_code}</div></Td>
                                        <Td className="font-mono text-xs">{row.host}</Td>
                                        <Td numeric>{row.event_type_count}</Td>
                                        <Td><StatusBadge status={row.status === 'active' ? 'active' : 'inactive'} label={t(`integrations.status_${row.status}`)} /></Td>
                                        <Td><StatusBadge status={HEALTH_TONE[row.health] ?? 'inactive'} label={t(`integrations.health_${row.health}`)} /></Td>
                                        <Td muted>{row.last_success_at ?? t('integrations.never')}</Td>
                                        <Td align="right">
                                            <AppActionMenu items={[
                                                { label: t('common.view'), href: route('integrations.subscriptions.show', row.id), show: true },
                                                { label: t('common.edit'), href: route('integrations.subscriptions.edit', row.id), show: can.manage },
                                            ]} />
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                {can.deliveries && (
                    <Card className="overflow-hidden p-0">
                        <div className="border-b border-(--app-border) px-5 py-4">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.failedDeliveries')}</h2>
                        </div>
                        {failedDeliveries.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-(--app-muted-foreground)">{t('integrations.noFailedDeliveries')}</p>
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('integrations.event')}</Th>
                                        <Th>{t('integrations.subscriptions')}</Th>
                                        <Th>{t('common.status')}</Th>
                                        <Th align="right">{t('integrations.attempts')}</Th>
                                        <Th>{t('integrations.httpStatus')}</Th>
                                        <Th>{t('integrations.errorCategory')}</Th>
                                        <Th>{t('integrations.nextAttempt')}</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {failedDeliveries.map((row) => (
                                        <Tr key={row.id}>
                                            <Td><span className="font-mono text-xs">{row.event_type}</span><div className="font-mono text-[11px] text-(--app-muted-foreground)">{row.event_id}</div></Td>
                                            <Td><Link href={route('integrations.subscriptions.show', row.subscription_id)} className="hover:text-primary">{row.subscription}</Link></Td>
                                            <Td><StatusBadge status={row.status === 'dead' ? 'failed' : 'pending'} label={t(`integrations.delivery_${row.status}`)} /></Td>
                                            <Td numeric>{row.attempts}</Td>
                                            <Td numeric>{row.last_http_status ?? '—'}</Td>
                                            <Td className="font-mono text-xs">{row.last_error_category ?? '—'}</Td>
                                            <Td muted>{row.next_attempt_at ?? '—'}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                )}

                <Card className="overflow-hidden p-0">
                    <div className="border-b border-(--app-border) px-5 py-4">
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('integrations.consumers')}</h2>
                        <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{t('integrations.consumersHint')}</p>
                    </div>
                    {consumers.length === 0 ? (
                        <p className="px-5 py-6 text-sm text-(--app-muted-foreground)">{t('integrations.noConsumers')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('integrations.application')}</Th>
                                    <Th align="right">{t('integrations.lastPosition')}</Th>
                                    <Th align="right">{t('integrations.behind')}</Th>
                                    <Th>{t('integrations.lastRead')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {consumers.map((row) => (
                                    <Tr key={row.code}>
                                        <Td>{row.name}<div className="font-mono text-xs text-(--app-muted-foreground)">{row.code}</div></Td>
                                        <Td numeric>{row.last_position}</Td>
                                        <Td numeric>{row.behind}</Td>
                                        <Td muted>{row.last_read_at ?? '—'}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
