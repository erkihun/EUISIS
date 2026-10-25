import { useEffect, useState, type JSX, type SVGProps } from 'react';
import { api } from '../api';
import AppMetricCard from '../components/AppMetricCard';
import { Briefcase, CityIcon, ClipboardClockIcon, IdCardIcon, UsersTwoIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { auditLabel } from '../lib/auditLabel';
import { formatDateTime, formatNumber } from '../lib/format';
import { Link } from '../router';
import { Alert, Card, buttonClassName } from '../ui';

type MetricKey = 'organizations_active' | 'units_active' | 'positions_active' | 'positions_held' | 'positions_without_holder'
    | 'employees_active' | 'assignments_current' | 'cards_active' | 'cards_suspended' | 'cards_revoked';

type Dashboard = {
    citywide: boolean;
    organization_count: number;
    metrics: Record<MetricKey, number>;
    recent: { action: string; created_at: string; actor_name: string | null }[];
};

/** The EUISIS Core dashboard's domains, limited to the figures this platform keeps. */
const DOMAINS: { key: string; href: string; icon: (p: SVGProps<SVGSVGElement>) => JSX.Element; metrics: MetricKey[] }[] = [
    { key: 'organization', href: '/organizations', icon: CityIcon, metrics: ['organizations_active', 'units_active'] },
    { key: 'positions', href: '/positions', icon: Briefcase, metrics: ['positions_active', 'positions_held', 'positions_without_holder'] },
    { key: 'employees', href: '/employees', icon: UsersTwoIcon, metrics: ['employees_active', 'assignments_current'] },
    { key: 'credentials', href: '/id-cards', icon: IdCardIcon, metrics: ['cards_active', 'cards_suspended', 'cards_revoked'] },
];

/** Figures that ask for someone's attention when they are above zero. */
const ATTENTION = new Set<MetricKey>(['cards_suspended']);

/** EUISIS Pages/Core/Dashboard.tsx: master-record counts in the viewer's scope, by domain, and the latest changes. */
export default function DashboardPage() {
    const { t } = useLocale();
    const [data, setData] = useState<Dashboard | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api<Dashboard>('/v1/dashboard').then(setData).catch(() => setError(true));
    }, []);

    const actionLabel = (action: string) => auditLabel(t, action);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.title')} description={t('core.description')} />}>
            <div className="space-y-6">
                {error && <Alert tone="danger">{t('platform.error')}</Alert>}
                {data && (
                    <p className="text-sm text-(--app-muted-foreground)">
                        {data.citywide ? t('core.scopeCitywide') : t('core.scopeOrganizations', { count: formatNumber(data.organization_count) })}
                    </p>
                )}

                {DOMAINS.map((domain) => {
                    const Icon = domain.icon;
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
                                <Link href={domain.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t(`core.domains.${domain.key}.open`)}
                                </Link>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {domain.metrics.map((key) => {
                                    const value = data?.metrics[key] ?? 0;
                                    return (
                                        <AppMetricCard
                                            key={key}
                                            loading={!data && !error}
                                            label={t(`core.metrics.${key}.label`)}
                                            value={formatNumber(value)}
                                            detail={t(`core.metrics.${key}.hint`)}
                                            variant={ATTENTION.has(key) ? (value > 0 ? 'warning' : 'neutral') : 'primary'}
                                        />
                                    );
                                })}
                            </div>
                        </section>
                    );
                })}

                {data && data.recent.length > 0 && (
                    <section aria-labelledby="core-recent" className="space-y-3">
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--app-surface) text-primary ring-1 ring-(--app-border)">
                                <ClipboardClockIcon className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <h2 id="core-recent" className="text-base font-semibold text-(--app-foreground)">{t('core.recent.title')}</h2>
                                <p className="text-sm text-(--app-muted-foreground)">{t('core.recent.description')}</p>
                            </div>
                        </div>
                        <Card className="p-0">
                            <ul className="divide-y divide-(--app-border)">
                                {data.recent.map((entry, index) => (
                                    <li key={index} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                        <span className="font-medium text-(--app-foreground)">{actionLabel(entry.action)}</span>
                                        <span className="text-(--app-muted-foreground)">{entry.actor_name ?? '—'} · {formatDateTime(entry.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
