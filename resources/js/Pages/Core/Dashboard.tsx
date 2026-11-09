import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import { ArrowLeftRightIcon, WidgetsIcon } from '@/Components/NavIcons';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AppMetricCard, Button, Card, TableCard, buttonClassName } from '@/Components/ui';
import { EmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { useBranding } from '@/hooks/useBranding';
import { formatNumber } from '@/utils/displayFormat';
import type { CoreActivity, CoreMetric, CoreMetrics } from '@/types/core';

interface Props {
    metrics: CoreMetrics;
    /** Latest master-data changes from the audit log; empty without `audit.view`. */
    recent: CoreActivity[];
}

/** Figures that ask for someone's attention when they are above zero. */
const ATTENTION = new Set(['employees_unplaced', 'cards_expiring', 'card_requests_open', 'integration_pending', 'cards_suspended', 'data_quality_findings']);

/**
 * The Core workspace's home: how many master records exist, in the viewer's
 * organization scope, and which of them need attention. Each domain opens its
 * existing page; nothing here edits a record.
 */
export default function CoreDashboard({ metrics, recent }: Props) {
    const { t } = useLocale();
    const { withBrand } = useBranding();
    const title = withBrand(t('core.title'));

    const value = (metric: CoreMetric): string => {
        if (metric.value === null || metric.value === '') return t('core.none');
        return typeof metric.value === 'number' ? formatNumber(metric.value) : metric.value;
    };
    const variant = (metric: CoreMetric) => ATTENTION.has(metric.key)
        ? (typeof metric.value === 'number' && metric.value > 0 ? 'warning' : 'neutral')
        : 'primary';

    // The audit log's own labels for event types, so both pages name an event the same way.
    const eventLabel = (eventType: string): string => {
        const key = `auditLogs.events.${eventType.replace(/\./g, '_')}`;
        const translated = t(key);
        return translated === key ? eventType.replace(/[._-]/g, ' ') : translated;
    };

    const exit = (
        <Button variant="outline" icon={<ArrowLeftRightIcon className="h-4 w-4" />} onClick={() => router.post(route('core.exit'))}>
            {withBrand(t('core.exit'))}
        </Button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('core.description')} actions={exit} />}>
            <Head title={title} />

            <div className="space-y-8">
                <p className="text-sm text-(--app-muted-foreground)">
                    {metrics.citywide
                        ? t('core.scopeCitywide')
                        : t('core.scopeOrganizations').replace(':count', formatNumber(metrics.organization_count ?? 0))}
                </p>

                {metrics.domains.length === 0 ? (
                    <Card className="p-0">
                        <EmptyState icon={<WidgetsIcon className="h-8 w-8" />} title={t('core.emptyTitle')} description={t('core.emptyDescription')} />
                    </Card>
                ) : metrics.domains.map((domain) => (
                    <DashboardSection
                        key={domain.key}
                        title={t(`core.domains.${domain.key}.title`)}
                        description={t(`core.domains.${domain.key}.description`)}
                        actions={domain.href ? (
                            <Link href={domain.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                {t(`core.domains.${domain.key}.open`)}
                            </Link>
                        ) : undefined}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {domain.metrics.map((metric) => (
                                <AppMetricCard
                                    key={metric.key}
                                    label={t(`core.metrics.${metric.key}.label`)}
                                    value={value(metric)}
                                    detail={t(`core.metrics.${metric.key}.hint`)}
                                    variant={variant(metric)}
                                />
                            ))}
                        </div>
                    </DashboardSection>
                ))}

                {recent.length > 0 && (
                    <DashboardSection title={t('core.recent.title')} description={t('core.recent.description')}>
                        <TableCard>
                            <ul className="divide-y divide-(--app-border)">
                                {recent.map((entry, index) => (
                                    <li key={index} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                        <span className="font-medium text-(--app-foreground)">{eventLabel(entry.event_type)}</span>
                                        <span className="text-(--app-muted-foreground)">
                                            {entry.actor_name ?? t('auditLogs.system')} · <LocalizedDateDisplay value={entry.created_at} withTime />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </TableCard>
                    </DashboardSection>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
