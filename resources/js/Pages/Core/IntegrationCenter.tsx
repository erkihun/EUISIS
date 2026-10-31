import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { CodeIcon, NetworkIcon } from '@/Components/NavIcons';
import { AppMetricCard, Table, TableCard, TBody, Td, Th, THead, Tr, buttonClassName } from '@/Components/ui';
import { Card, EmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import IntegrationWorkspace from '@/Components/coreIntegration/IntegrationWorkspace';
import type { IntegrationCenterData } from '@/Components/coreIntegration/types';

interface ApplicationRow {
    id: string;
    name: string;
    code: string;
    status: string;
    organization_access: string | null;
    scope_count: number;
    token_count: number;
    next_token_expiry: string | null;
    last_used_at: string | null;
}

type EventFigures = Record<string, number | string | null>;

interface Props {
    applications: ApplicationRow[] | null;
    events: EventFigures | null;
    can: { applications: boolean; events: boolean };
    /** The standalone Core's own center (API clients, webhooks, deliveries, events, API calls), where the server offers it. */
    center?: IntegrationCenterData | null;
}

/**
 * Core › Integration Center: partner applications and the event pipeline on one page.
 * No secret or token is ever sent here; details open on the existing screens.
 */
export default function CoreIntegrationCenter({ applications, events, can, center = null }: Props) {
    const { t } = useLocale();
    const figure = (value: number | string | null | undefined) => (value === null || value === undefined ? t('core.none') : typeof value === 'number' ? formatNumber(value) : value);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.integration.title')} description={t(center ? 'core.integration.center.description' : 'core.integration.description')} />}>
            <Head title={t('core.integration.title')} />

            {center ? <IntegrationWorkspace center={center} /> : (
            <div className="space-y-8">
                {events && (
                    <DashboardSection
                        title={t('core.integration.events')}
                        actions={<Link href={route('integrations.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.integration.openEvents')}</Link>}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <AppMetricCard label={t('core.integration.pendingEvents')} value={figure(events.pending_events)} variant={Number(events.pending_events) > 0 ? 'warning' : 'neutral'} />
                            <AppMetricCard label={t('core.integration.publishedToday')} value={figure(events.published_24h)} variant="primary" />
                            <AppMetricCard label={t('core.integration.failedDeliveries')} value={figure(events.dead_deliveries)} variant={Number(events.dead_deliveries) > 0 ? 'danger' : 'neutral'} />
                            <AppMetricCard label={t('core.integration.activeSubscriptions')} value={figure(events.active_subscriptions)} variant="primary" />
                        </div>
                    </DashboardSection>
                )}

                {can.applications && (
                    <DashboardSection
                        title={t('core.integration.applications')}
                        actions={(
                            <>
                                <Link href={route('api-management.docs')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.integration.apiDocs')}</Link>
                                <Link href={route('api-management.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('core.integration.manageApplications')}</Link>
                            </>
                        )}
                    >
                        <TableCard>
                            {!applications || applications.length === 0 ? (
                                <EmptyState icon={<CodeIcon className="h-8 w-8" />} title={t('core.integration.noApplications')} description={t('core.integration.noApplicationsHint')} />
                            ) : (
                                <Table>
                                    <THead>
                                        <Tr>
                                            <Th>{t('core.integration.application')}</Th>
                                            <Th>{t('core.integration.status')}</Th>
                                            <Th>{t('core.integration.bound')}</Th>
                                            <Th align="right">{t('core.integration.scopes')}</Th>
                                            <Th align="right">{t('core.integration.tokens')}</Th>
                                            <Th>{t('core.integration.nextExpiry')}</Th>
                                            <Th>{t('core.integration.lastUsed')}</Th>
                                        </Tr>
                                    </THead>
                                    <TBody>
                                        {applications.map((application) => (
                                            <Tr key={application.id}>
                                                <Td>
                                                    <Link href={route('api-management.show', application.id)} className="font-medium text-(--app-foreground) hover:text-primary">{application.name}</Link>
                                                    <div className="font-mono text-xs text-(--app-muted-foreground)">{application.code}</div>
                                                </Td>
                                                <Td><StatusBadge status={application.status} /></Td>
                                                <Td muted>{application.organization_access === 'scoped' ? t('core.integration.boundScoped') : t('core.integration.boundAll')}</Td>
                                                <Td numeric>{application.scope_count}</Td>
                                                <Td numeric>{application.token_count}</Td>
                                                <Td muted><LocalizedDateDisplay value={application.next_token_expiry} fallback={t('core.integration.noExpiry')} /></Td>
                                                <Td muted><LocalizedDateDisplay value={application.last_used_at} withTime fallback={t('core.integration.never')} /></Td>
                                            </Tr>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </TableCard>
                    </DashboardSection>
                )}

                {!events && !can.applications && (
                    <Card className="p-0"><EmptyState icon={<NetworkIcon className="h-8 w-8" />} title={t('core.emptyTitle')} description={t('core.emptyDescription')} /></Card>
                )}
            </div>
            )}
        </AuthenticatedLayout>
    );
}
