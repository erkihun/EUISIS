import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import ChartCard from '@/Components/dashboard/ChartCard';
import KpiCard from '@/Components/dashboard/KpiCard';
import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import { SimpleBarChart, SimpleLineChart } from '@/Components/dashboard/charts/SimpleCharts';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Card, Select, TableCard, actionLinkClassName, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCan } from '@/hooks/useCan';
import { formatNumber } from '@/utils/displayFormat';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import type { CourtDashboard, CourtDashboardMember, DataStorage, ServiceHealth } from '@/court/types/dashboard';

interface Props {
    /** Null without cases.view. */
    dashboard: CourtDashboard | null;
    ranges: string[];
    /** Null without settings.manage (as is dataStorage). */
    serviceHealth: ServiceHealth | null;
    dataStorage: DataStorage | null;
}

/**
 * The court-ms admin dashboard: case totals, the registration trend, each
 * team's and member's workload, case types, applicants and service health —
 * what the Blade dashboard showed, on the EUISIS kit. "All time" is the
 * default; a period re-reads every figure for that period. The case figures
 * reach staff with cases.view only, the system panels settings.manage only.
 */
export default function CourtDashboardPage({ dashboard, ranges, serviceHealth, dataStorage }: Props) {
    const { t } = useLocale();
    const title = t('cm.dashboard.title');

    const changeRange = (range: string) => {
        router.get(route('court.admin.dashboard'), range === 'all' ? {} : { range }, { preserveScroll: true, preserveState: true, only: ['dashboard'] });
    };

    const rangeSelect = dashboard ? (
        <label className="flex items-center gap-2 text-sm text-(--app-muted-foreground)">
            <span>{t('court.dashboard.period')}</span>
            <Select value={dashboard.range} onChange={(event) => changeRange(event.target.value)} className="w-auto min-w-40">
                {['all', ...ranges].map((range) => (
                    <option key={range} value={range}>{t(`court.dashboard.ranges.${range}`)}</option>
                ))}
            </Select>
        </label>
    ) : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.dashboard.operational_overview_subtitle')} actions={rangeSelect} />}>
            <Head title={title} />

            <div className="space-y-8">
                {dashboard
                    ? <CaseStatistics dashboard={dashboard} />
                    : <Card><p className="text-sm text-(--app-muted-foreground)">{t('cm.dashboard.no_case_access')}</p></Card>}
                {serviceHealth && dataStorage && <SystemPanels serviceHealth={serviceHealth} dataStorage={dataStorage} />}
            </div>
        </AuthenticatedLayout>
    );
}

/** The case figures, for staff with cases.view. */
function CaseStatistics({ dashboard }: { dashboard: CourtDashboard }) {
    const { t, locale } = useLocale();
    const { can } = useCan();
    const system = useCalendarSystem();
    const kpis = dashboard.kpis;
    const share = (value: number) => (kpis.total > 0 ? `${Math.round((value / kpis.total) * 100)}% ${t('cm.dashboard.of_total')}` : null);

    const trendLabel = (iso: string): string => dashboard.mode === 'daily'
        ? formatDateDisplay(iso, system, locale)
        : new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en', { month: 'short', year: 'numeric' }).format(new Date(`${iso}T00:00:00`));
    const caseTypeLabel = (key: string) => (key === '' || key === 'Unknown' ? t('cm.dashboard.unknown_case_type') : key);
    const genderLabel = (key: string) => {
        const translated = t(`cm.auth.${key.toLowerCase()}`);
        return key === '' ? t('cm.dashboard.unknown_case_type') : translated.startsWith('cm.') ? key : translated;
    };
    const statusLabel = (status: string) => {
        const translated = t(`cm.cases.status.${status}`);
        return translated.startsWith('cm.') ? status : translated;
    };

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiCard title={t('cm.dashboard.total_cases')} value={formatNumber(kpis.total)} icon="queue" tone="primary" featured
                    comparisonLabel={dashboard.range === 'all' ? t('cm.dashboard.all_time') : t(`court.dashboard.ranges.${dashboard.range}`)}
                    href={can('cases.view') ? route('court.cases.index') : null} />
                <KpiCard title={t('cm.dashboard.pending_cases_label')} value={formatNumber(kpis.pending)} icon="alert" tone="warning" comparisonLabel={share(kpis.pending)} />
                <KpiCard title={t('cm.dashboard.active_cases_label')} value={formatNumber(kpis.active)} icon="activity" tone="primary" comparisonLabel={share(kpis.active)} />
                <KpiCard title={t('cm.dashboard.resolved_cases_label')} value={formatNumber(kpis.resolved)} icon="shield" tone="success" comparisonLabel={share(kpis.resolved)} />
            </div>

            <div className="grid gap-4 xl:grid-cols-3">
                <div className="xl:col-span-2">
                    <ChartCard title={dashboard.range === 'all' ? t('cm.dashboard.new_cases_per_month') : t('cm.dashboard.new_cases')} description={t('cm.dashboard.case_activity')}>
                        <SimpleLineChart data={dashboard.trend.map((point) => ({ label: trendLabel(point.key), value: point.value }))} emptyTitle={t('cm.dashboard.no_cases_yet')} />
                    </ChartCard>
                </div>
                <ChartCard title={t('cm.dashboard.cases_by_type')} description={t('cm.dashboard.case_type_distribution')}
                    action={can('reports.view') ? <Link href={route('court.reports.index')} className={actionLinkClassName({})}>{t('cm.dashboard.view_report')}</Link> : undefined}>
                    <SimpleBarChart data={dashboard.caseTypes} labelFor={caseTypeLabel} emptyTitle={t('cm.dashboard.no_cases_yet')} />
                </ChartCard>
            </div>

            <DashboardSection title={t('cm.dashboard.case_workload')}>
                <div className="grid gap-4 xl:grid-cols-3">
                    <div className="xl:col-span-2">
                        <TableCard title={t('cm.dashboard.cases_by_member')} description={t('cm.dashboard.assigned_cases_by_member')}>
                            {dashboard.members.length === 0
                                ? <p className="px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{t('cm.dashboard.no_member_case_counts')}</p>
                                : <MemberList members={dashboard.members} t={t} caseTypeLabel={caseTypeLabel} />}
                        </TableCard>
                    </div>
                    <TableCard title={t('cm.dashboard.cases_by_team')} description={t('cm.dashboard.assigned_cases_by_team')}>
                        {dashboard.teams.length === 0
                            ? <p className="px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{t('cm.dashboard.no_team_case_counts')}</p>
                            : (
                                <ul className="divide-y divide-(--app-border)">
                                    {dashboard.teams.map((team) => (
                                        <li key={team.id} className="px-4 py-3">
                                            <div className="flex items-center justify-between gap-3 text-sm">
                                                <span className="min-w-0 truncate font-medium text-(--app-foreground)">{team.name}</span>
                                                <span className="font-semibold tabular-nums text-(--app-foreground)">{formatNumber(team.cases)}</span>
                                            </div>
                                            <Meter value={team.cases} max={Math.max(...dashboard.teams.map((row) => row.cases), 1)} tone="bg-emerald-500" />
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </TableCard>
                </div>
            </DashboardSection>

            <div className="grid gap-4 xl:grid-cols-3">
                <div className="xl:col-span-2">
                    <TableCard title={t('cm.dashboard.recent_cases')}
                        actions={can('cases.view') ? <Link href={route('court.cases.index')} className={actionLinkClassName({})}>{t('cm.dashboard.view_all')}</Link> : undefined}>
                        {dashboard.recent.length === 0
                            ? <p className="px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{t('cm.dashboard.no_recent_cases')}</p>
                            : (
                                <ul className="divide-y divide-(--app-border)">
                                    {dashboard.recent.map((row) => (
                                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                                            <div className="min-w-0">
                                                <Link href={route('court.cases.show', row.id)} className="font-mono text-xs font-semibold text-primary hover:underline">{row.case_number}</Link>
                                                <p className="truncate text-(--app-foreground)">{row.title}</p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={row.created_at} /></span>
                                                <StatusBadge status={row.status} label={statusLabel(row.status)} />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </TableCard>
                </div>
                <ChartCard title={t('cm.dashboard.applicants_by_gender')}>
                    <StatusDistribution data={dashboard.gender} labelFor={genderLabel} emptyTitle={t('cm.dashboard.no_cases_yet')} />
                </ChartCard>
            </div>
        </>
    );
}

/** Service health and storage, for staff with settings.manage. */
function SystemPanels({ serviceHealth, dataStorage }: { serviceHealth: ServiceHealth; dataStorage: DataStorage }) {
    const { t } = useLocale();

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <h2 className="text-sm font-semibold text-(--app-foreground)">{t('cm.dashboard.service_health')}</h2>
                <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                    <HealthItem label={t('cm.dashboard.database_connection')} ok={serviceHealth.database}
                        value={serviceHealth.database ? t('cm.dashboard.operational') : t('cm.dashboard.unavailable')} />
                    <HealthItem label={t('cm.dashboard.queue_status')} ok={serviceHealth.queue_ready}
                        value={serviceHealth.queue_waiting === null ? '—' : `${formatNumber(serviceHealth.queue_waiting)} · ${t('cm.dashboard.jobs_waiting')}`} />
                </dl>
                <p className="mt-4 text-xs font-medium text-(--app-muted-foreground)">{t('cm.dashboard.email_sms_gateways')}</p>
                <div className="mt-2 flex flex-wrap gap-2">
                    {(['mail', 'sms', 'telegram', 'in_app'] as const).map((channel) => {
                        const enabled = serviceHealth[channel];
                        const ready = channel === 'sms' ? serviceHealth.sms_ready : enabled;
                        return (
                            <span key={channel} className={cx('rounded-full border px-2.5 py-1 text-xs',
                                ready ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : enabled ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                                        : 'border-(--app-border) bg-(--app-surface-muted) text-(--app-muted-foreground)')}>
                                {t(`court.dashboard.channels.${channel}`)}: {ready ? t('cm.dashboard.operational') : enabled ? t('cm.dashboard.enabled') : t('cm.dashboard.info_disabled')}
                            </span>
                        );
                    })}
                </div>
            </Card>
            <Card>
                <h2 className="text-sm font-semibold text-(--app-foreground)">{t('cm.dashboard.data_storage')}</h2>
                <dl className="mt-4 grid grid-cols-2 gap-3">
                    <StorageItem label={t('cm.dashboard.database_tables')} value={formatNumber(dataStorage.tables)} />
                    <StorageItem label={t('cm.dashboard.audit_events')} value={formatNumber(dataStorage.audit_events)} />
                    <StorageItem label={t('cm.dashboard.database_size')} value={dataStorage.database_size ?? '—'} />
                    <StorageItem label={t('cm.dashboard.storage_free')}
                        value={dataStorage.storage_free ? `${(dataStorage.storage_free / 1073741824).toFixed(1)} GB` : '—'} />
                </dl>
            </Card>
        </div>
    );
}

function MemberList({ members, t, caseTypeLabel }: { members: CourtDashboardMember[]; t: (key: string) => string; caseTypeLabel: (key: string) => string }) {
    const max = Math.max(...members.map((member) => member.cases), 1);

    return (
        <ul className="divide-y divide-(--app-border)">
            {members.map((member) => (
                <li key={member.id} className="px-4 py-3">
                    <div className="flex items-center gap-3">
                        {member.avatar_url
                            ? <img src={member.avatar_url} alt="" className="h-9 w-9 shrink-0 rounded-full object-cover" />
                            : <span aria-hidden="true" className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">{member.name.slice(0, 1).toUpperCase()}</span>}
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-(--app-foreground)">{member.name}</p>
                            <p className="truncate text-xs text-(--app-muted-foreground)">{member.team ?? t('cm.dashboard.no_team')}</p>
                        </div>
                        <div className="text-right">
                            <p className="text-sm font-semibold tabular-nums text-(--app-foreground)">{formatNumber(member.cases)}</p>
                            <p className="text-[11px] text-(--app-muted-foreground)">{t('cm.app.Cases')}</p>
                        </div>
                    </div>
                    <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-(--app-muted-foreground)">
                        <div className="flex gap-1"><dt>{t('cm.dashboard.pending')}</dt><dd className="font-semibold text-(--app-foreground)">{formatNumber(member.pending)}</dd></div>
                        <div className="flex gap-1"><dt>{t('cm.dashboard.active')}</dt><dd className="font-semibold text-(--app-foreground)">{formatNumber(member.active)}</dd></div>
                        <div className="flex gap-1"><dt>{t('cm.dashboard.resolved')}</dt><dd className="font-semibold text-(--app-foreground)">{formatNumber(member.resolved)}</dd></div>
                    </dl>
                    {member.types.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5" aria-label={t('cm.dashboard.type')}>
                            {member.types.map((type) => (
                                <span key={type.label ?? '__unknown__'} className="inline-flex items-center gap-1 rounded-full border border-(--app-border) px-2 py-0.5 text-[11px] text-(--app-muted-foreground)">
                                    <span className="max-w-32 truncate">{type.label === null ? t('cm.dashboard.unknown_case_type') : caseTypeLabel(type.label)}</span>
                                    <span className="font-semibold text-(--app-foreground)">{formatNumber(type.count)}</span>
                                </span>
                            ))}
                        </div>
                    )}
                    <Meter value={member.cases} max={max} tone="bg-primary" />
                </li>
            ))}
        </ul>
    );
}

function Meter({ value, max, tone }: { value: number; max: number; tone: string }) {
    return (
        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
            <div className={cx('h-full rounded-full', tone)} style={{ width: `${Math.round((value / max) * 100)}%` }} />
        </div>
    );
}

function HealthItem({ label, value, ok }: { label: string; value: string; ok: boolean }) {
    return (
        <div className="rounded-lg border border-(--app-border) p-3">
            <dt className="flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                <span aria-hidden="true" className={cx('h-2 w-2 rounded-full', ok ? 'bg-emerald-500' : 'bg-red-500')} />
                {label}
            </dt>
            <dd className="mt-1 text-sm font-semibold text-(--app-foreground)">{value}</dd>
        </div>
    );
}

function StorageItem({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-(--app-border) p-3">
            <dt className="text-xs text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 text-sm font-semibold text-(--app-foreground)">{value}</dd>
        </div>
    );
}
