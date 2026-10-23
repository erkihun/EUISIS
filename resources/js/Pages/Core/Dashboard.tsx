import { Head, Link, router } from '@inertiajs/react';
import type { JSX, SVGProps } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import { ArrowLeftRightIcon, CityIcon, Briefcase, IdCardIcon, ShieldUserIcon, UsersTwoIcon, WidgetsIcon } from '@/Components/NavIcons';
import { Button, Card, buttonClassName } from '@/Components/ui';
import { EmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { useBranding } from '@/hooks/useBranding';
import { formatNumber } from '@/utils/displayFormat';
import type { CoreMetric, CoreMetrics } from '@/types/core';

interface Props {
    metrics: CoreMetrics;
}

const DOMAIN_ICONS: Record<string, (p: SVGProps<SVGSVGElement>) => JSX.Element> = {
    organization: CityIcon,
    positions: Briefcase,
    employees: UsersTwoIcon,
    credentials: IdCardIcon,
    governance: ShieldUserIcon,
};

/** Figures that ask for someone's attention when they are above zero. */
const ATTENTION = new Set(['employees_unplaced', 'cards_expiring', 'card_requests_open', 'integration_pending']);

/**
 * The Core workspace's home: how many master records exist, in the viewer's
 * organization scope, and which of them need attention. Each domain opens its
 * existing page; nothing here edits a record.
 */
export default function CoreDashboard({ metrics }: Props) {
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

    const exit = (
        <Button variant="outline" icon={<ArrowLeftRightIcon className="h-4 w-4" />} onClick={() => router.post(route('core.exit'))}>
            {withBrand(t('core.exit'))}
        </Button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('core.description')} actions={exit} />}>
            <Head title={title} />

            <div className="space-y-6">
                <p className="text-sm text-(--app-muted-foreground)">
                    {metrics.citywide
                        ? t('core.scopeCitywide')
                        : t('core.scopeOrganizations').replace(':count', formatNumber(metrics.organization_count ?? 0))}
                </p>

                {metrics.domains.length === 0 ? (
                    <Card>
                        <EmptyState icon={<WidgetsIcon className="h-8 w-8" />} title={t('core.emptyTitle')} description={t('core.emptyDescription')} />
                    </Card>
                ) : metrics.domains.map((domain) => {
                    const Icon = DOMAIN_ICONS[domain.key] ?? WidgetsIcon;
                    return (
                        <section key={domain.key} aria-labelledby={`core-domain-${domain.key}`} className="space-y-3">
                            <div className="flex flex-wrap items-end justify-between gap-2">
                                <div className="flex min-w-0 items-start gap-3">
                                    <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--app-surface) text-primary ring-1 ring-(--app-border)">
                                        <Icon className="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0">
                                        <h2 id={`core-domain-${domain.key}`} className="text-base font-semibold text-(--app-foreground)">{t(`core.domains.${domain.key}.title`)}</h2>
                                        <p className="text-sm text-(--app-muted-foreground)">{t(`core.domains.${domain.key}.description`)}</p>
                                    </div>
                                </div>
                                {domain.href && (
                                    <Link href={domain.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                        {t(`core.domains.${domain.key}.open`)}
                                    </Link>
                                )}
                            </div>
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
                        </section>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
