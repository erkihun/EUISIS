import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { Card, StatusBadge, TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import { useTransportLabels } from '@/Components/transport/labels';
import { DateText, Kpi, Percent } from '@/Components/transport/TransportShared';
import type { DailyUsageRow, DataQualityCheck, ProviderPerformanceRow, ReportFilters } from '@/types/transport';

type Today = { trips: number; completed: number; cancelled: number; in_service: number; verified_boardings: number; morning: number; evening: number; riders: number; rejected: number; vehicles_active: number; open_exceptions: number; stale_devices: number; settlements_in_review: number };

export default function Overview({ today, month, dataQuality, can }: { today: Today; month: { filters: ReportFilters; daily: DailyUsageRow[]; providers: ProviderPerformanceRow[] }; dataQuality: DataQualityCheck[]; can: Record<string, boolean> }) {
    const { t, name, label } = useTransportLabels();
    const issues = dataQuality.filter((c) => c.count !== 0);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.overview')} description={t('transport.overviewIntro')} actions={<div className="flex flex-wrap gap-2">
            {can.trips && <Link href={route('transport.trips.index')} className={buttonClassName({ variant: 'outline' })}>{t('transport.trips')}</Link>}
            {can.reports && <Link href={route('transport.reports.index')} className={buttonClassName({ variant: 'outline' })}>{t('transport.reports')}</Link>}
        </div>} />}>
            <Head title={t('transport.overview')} />
            <div className="space-y-6">
                <section>
                    <h2 className="mb-2 text-sm font-semibold text-(--app-muted-foreground)">{t('transport.today')}</h2>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                        <Kpi label={t('transport.statTripsToday')} value={today.trips} hint={`${today.completed} ${t('transport.statuses.completed')} · ${today.in_service} ${t('transport.inService')}`} />
                        <Kpi label={t('transport.cancelledTrips')} value={today.cancelled} tone={today.cancelled > 0 ? 'warning' : undefined} />
                        <Kpi label={t('transport.verifiedBoardings')} value={today.verified_boardings} hint={`${t('transport.riders')}: ${today.riders}`} />
                        <Kpi label={t('transport.morningRiders')} value={today.morning} />
                        <Kpi label={t('transport.eveningRiders')} value={today.evening} />
                        <Kpi label={t('transport.statActiveVehicles')} value={today.vehicles_active} />
                        <Kpi label={t('transport.rejectedScans')} value={today.rejected} tone={today.rejected > 0 ? 'warning' : undefined} />
                        <Kpi label={t('transport.openExceptions')} value={today.open_exceptions} tone={today.open_exceptions > 0 ? 'warning' : undefined} />
                        <Kpi label={t('transport.staleDevices')} value={today.stale_devices} tone={today.stale_devices > 0 ? 'warning' : undefined} />
                        <Kpi label={t('transport.settlementsInReview')} value={today.settlements_in_review} />
                    </div>
                </section>
                <div className="grid gap-6 xl:grid-cols-2">
                    <TableCard title={t('transport.dailyUsage')} description={`${month.filters.date_from} – ${month.filters.date_to}`}>
                        {month.daily.length === 0 ? <p className="p-4 text-sm text-(--app-muted-foreground)">{t('transport.noBoardings')}</p> : (
                            <div className="relative overflow-x-auto"><table className={tableClassName}>
                                <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.date')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.riders')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.morningRiders')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.eveningRiders')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.boardings')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.completedTrips')}</th></tr></thead>
                                <tbody className={tableBodyClassName}>{month.daily.slice(-14).map((d) => <tr key={d.date} className={tableRowClassName}><td className={tableCellClassName}><DateText value={d.date} /></td><td className={`${tableCellClassName} text-right tabular-nums`}>{d.unique_riders}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{d.morning_riders}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{d.evening_riders}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{d.boardings}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{d.completed_trips}</td></tr>)}</tbody>
                            </table></div>
                        )}
                    </TableCard>
                    <TableCard title={t('transport.providerPerformance')}>
                        {month.providers.length === 0 ? <p className="p-4 text-sm text-(--app-muted-foreground)">{t('transport.noRecords')}</p> : (
                            <div className="relative overflow-x-auto"><table className={tableClassName}>
                                <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.provider')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.completedTrips')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.cancelledTrips')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.verifiedBoardings')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.utilization')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.exceptions')}</th></tr></thead>
                                <tbody className={tableBodyClassName}>{month.providers.map((p) => <tr key={p.provider_id} className={tableRowClassName}><td className={tableCellClassName}>{p.provider_code} — {name(p)}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{p.completed_trips}/{p.scheduled_trips}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{p.cancelled_trips}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{p.verified_boardings}</td><td className={`${tableCellClassName} text-right`}><Percent value={p.utilization_percent} /></td><td className={`${tableCellClassName} text-right tabular-nums`}>{p.exceptions}</td></tr>)}</tbody>
                            </table></div>
                        )}
                    </TableCard>
                </div>
                <Card>
                    <h2 className="text-sm font-semibold">{t('transport.dataQualityTitle')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{t('transport.dataQualityHelp')}</p>
                    {issues.length === 0 ? <p className="mt-3 text-sm text-emerald-700">{t('transport.dataQualityClean')}</p> : (
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {issues.map((c) => <li key={c.code} className="flex items-center justify-between gap-2 rounded-control border border-(--app-border) px-3 py-2 text-sm"><span>{label('dataQuality', c.code)}</span><StatusBadge tone={c.count < 0 ? 'neutral' : c.severity === 'critical' ? 'danger' : c.severity === 'warning' ? 'warning' : 'info'}>{c.count < 0 ? '?' : c.count}</StatusBadge></li>)}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
