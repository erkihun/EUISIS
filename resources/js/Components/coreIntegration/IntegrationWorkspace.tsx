import { Link, usePage } from '@inertiajs/react';
import TokenReveal from '@/Components/apiManagement/TokenReveal';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import { cx } from '@euisis/ui';
import ApiClientsPanel from './ApiClientsPanel';
import { ApiCallsPanel, DeliveriesPanel, EventLogPanel } from './ActivityPanels';
import WebhooksPanel from './WebhooksPanel';
import type { IntegrationCenterData, IntegrationTab, SecretOnce } from './types';

const TABS: IntegrationTab[] = ['clients', 'webhooks', 'deliveries', 'events', 'logs'];

/** The Core's Integration Center: the figures, the one-time secret after a create or rotate, and one tab at a time. */
export default function IntegrationWorkspace({ center }: { center: IntegrationCenterData }) {
    const { t } = useLocale();
    const secret = (usePage().props as { flash?: { integration_secret_once?: SecretOnce | null } }).flash?.integration_secret_once ?? null;
    const figures = center.summary;

    return (
        <div className="space-y-6">
            {secret && (
                <TokenReveal
                    token={secret.secret}
                    title={`${t(`core.integration.center.secretTitle.${secret.kind}`)}: ${secret.name}`}
                    hint={t('core.integration.center.secretHint')}
                />
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <AppMetricCard label={t('core.integration.center.summary.active_clients')} value={formatNumber(figures.active_clients)} variant="primary" />
                <AppMetricCard label={t('core.integration.center.summary.calls_24h')} value={formatNumber(figures.calls_24h)} variant="neutral" />
                <AppMetricCard label={t('core.integration.center.summary.errors_24h')} value={formatNumber(figures.errors_24h)} variant={figures.errors_24h > 0 ? 'warning' : 'neutral'} />
                <AppMetricCard label={t('core.integration.center.summary.failed_deliveries')} value={formatNumber(figures.failed_deliveries)} variant={figures.failed_deliveries > 0 ? 'danger' : 'neutral'} />
            </div>

            <nav aria-label={t('core.integration.center.sections')} className="flex gap-1 overflow-x-auto border-b border-(--app-border)">
                {TABS.map((tab) => (
                    <Link
                        key={tab}
                        href={center.urls.page}
                        data={{ tab }}
                        preserveScroll
                        aria-current={center.tab === tab ? 'page' : undefined}
                        className={cx('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors',
                            center.tab === tab ? 'border-primary font-medium text-primary' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)')}
                    >
                        {t(`core.integration.center.tabs.${tab}`)}
                    </Link>
                ))}
            </nav>

            {center.tab === 'clients' && center.clients && <ApiClientsPanel clients={center.clients} options={center.options} storeUrl={center.urls.store_client} />}
            {center.tab === 'webhooks' && center.webhooks && <WebhooksPanel webhooks={center.webhooks} options={center.options} storeUrl={center.urls.store_webhook} />}
            {center.tab === 'deliveries' && center.deliveries && <DeliveriesPanel page={center.deliveries} center={center} />}
            {center.tab === 'events' && center.events && <EventLogPanel page={center.events} center={center} />}
            {center.tab === 'logs' && center.logs && <ApiCallsPanel page={center.logs} center={center} />}
        </div>
    );
}
