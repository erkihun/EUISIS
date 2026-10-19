import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { Card, EmptyState, FilterField, FilterPanel, Input, Select, TableCard, Tabs, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import { useState, type ReactNode } from 'react';
import { ReportView } from '@/Components/transport/TransportData';
import { useTransportLabels } from '@/Components/transport/labels';
import { DateText, Percent } from '@/Components/transport/TransportShared';
import type { DailyUsageRow, ProviderOption, ProviderPerformanceRow, ReportFilters, RouteRef, RouteUtilizationRow, TransportReport, VehicleUtilizationRow } from '@/types/transport';

type Props = {
    report: TransportReport;
    dailyUsage: DailyUsageRow[];
    routeUtilization: RouteUtilizationRow[];
    vehicleUtilization: VehicleUtilizationRow[];
    providerPerformance: ProviderPerformanceRow[];
    filters: ReportFilters;
    providers: ProviderOption[];
    routes: (RouteRef & { provider_id: string })[];
    can: { viewTransactions: boolean; export: boolean };
};

const th = (extra = '') => `${tableHeaderCellClassName} ${extra}`;
const td = (extra = '') => `${tableCellClassName} ${extra}`;
const num = `${tableCellClassName} text-right tabular-nums`;

/** Reports on verified boardings only: what the scanner recorded, never what was planned or claimed. */
export default function Index({ report, dailyUsage, routeUtilization, vehicleUtilization, providerPerformance, filters, providers, routes, can }: Props) {
    const { t, name } = useTransportLabels();
    const [draft, setDraft] = useState({ date_from: filters.date_from, date_to: filters.date_to, provider_id: filters.provider_id ?? '', route_id: filters.route_id ?? '', direction: filters.direction ?? '', verification_method: filters.verification_method ?? '' });
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) as Record<string, string>;
    const ownRoutes = routes.filter((r) => !draft.provider_id || r.provider_id === draft.provider_id);

    function apply(next = draft) {
        router.get(route('transport.reports.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveScroll: true, preserveState: true });
    }

    const exportLinks = (type: string): ReactNode => (can.export ? (
        <div className="flex gap-2">
            {(['csv', 'xlsx'] as const).map((format) => <a key={format} href={route('transport.reports.export', { type, format, ...query })} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t(`transport.export_${format}`)}</a>)}
        </div>
    ) : undefined);

    const table = (rows: unknown[], head: ReactNode, body: ReactNode) => (rows.length === 0 ? <EmptyState title={t('transport.noRecords')} /> : (
        <div className="relative overflow-x-auto"><table className={tableClassName}><thead className={tableHeadClassName}><tr>{head}</tr></thead><tbody className={tableBodyClassName}>{body}</tbody></table></div>
    ));

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.reports')} description={t('transport.reportsIntro')} />}>
            <Head title={t('transport.reports')} />
            <div className="space-y-6">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ date_from: '', date_to: '', provider_id: '', route_id: '', direction: '', verification_method: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.route_id || filters.direction || filters.verification_method)}>
                    <FilterField label={t('transport.dateFrom')}><Input type="date" value={draft.date_from} onChange={(e) => setDraft({ ...draft, date_from: e.target.value })} /></FilterField>
                    <FilterField label={t('transport.dateTo')}><Input type="date" value={draft.date_to} onChange={(e) => setDraft({ ...draft, date_to: e.target.value })} /></FilterField>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value, route_id: '' })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.route')}><Select value={draft.route_id} onChange={(e) => setDraft({ ...draft, route_id: e.target.value })}><option value="">{t('transport.all')}</option>{ownRoutes.map((r) => <option key={r.id} value={r.id}>{r.route_code} — {name(r)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.direction')}><Select value={draft.direction} onChange={(e) => setDraft({ ...draft, direction: e.target.value })}><option value="">{t('transport.all')}</option><option value="inbound">{t('transport.directions.inbound')}</option><option value="outbound">{t('transport.directions.outbound')}</option></Select></FilterField>
                    <FilterField label={t('transport.method')}><Select value={draft.verification_method} onChange={(e) => setDraft({ ...draft, verification_method: e.target.value })}><option value="">{t('transport.all')}</option><option value="qr">{t('transport.methods.qr')}</option><option value="nfc">{t('transport.methods.nfc')}</option><option value="manual_exception">{t('transport.methods.manual_exception')}</option></Select></FilterField>
                </FilterPanel>

                <Tabs items={[
                    { id: 'boardings', label: t('transport.boardings'), content: (
                        <div className="space-y-4">
                            {can.export && <div className="flex justify-end"><a href={route('transport.transactions.export', query)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('transport.export')}</a></div>}
                            <ReportView report={report} transactionsHref={can.viewTransactions ? route('transport.transactions.index', query) : undefined} />
                        </div>
                    ) },
                    { id: 'daily', label: t('transport.dailyUsage'), content: (
                        <TableCard title={t('transport.dailyUsage')} description={t('transport.dailyUsageHelp')} actions={exportLinks('daily_usage')}>
                            {table(dailyUsage,
                                <><th className={th()}>{t('transport.date')}</th><th className={th('text-right')}>{t('transport.assignedEmployees')}</th><th className={th('text-right')}>{t('transport.uniqueRiders')}</th><th className={th('text-right')}>{t('transport.morningRiders')}</th><th className={th('text-right')}>{t('transport.eveningRiders')}</th><th className={th('text-right')}>{t('transport.boardings')}</th><th className={th('text-right')}>{t('transport.completedTrips')}</th><th className={th('text-right')}>{t('transport.vehiclesUsed')}</th></>,
                                dailyUsage.map((d) => <tr key={d.date} className={tableRowClassName}><td className={td()}><DateText value={d.date} /></td><td className={num}>{d.assigned_employees}</td><td className={num}>{d.unique_riders}</td><td className={num}>{d.morning_riders}</td><td className={num}>{d.evening_riders}</td><td className={num}>{d.boardings}</td><td className={num}>{d.completed_trips}</td><td className={num}>{d.vehicles_used}</td></tr>))}
                        </TableCard>
                    ) },
                    { id: 'routes', label: t('transport.routeUtilization'), content: (
                        <TableCard title={t('transport.routeUtilization')} description={t('transport.routeUtilizationHelp')} actions={exportLinks('route_utilization')}>
                            {table(routeUtilization,
                                <><th className={th()}>{t('transport.route')}</th><th className={th('text-right')}>{t('transport.assignedEmployees')}</th><th className={th('text-right')}>{t('transport.uniqueRiders')}</th><th className={th('text-right')}>{t('transport.boardings')}</th><th className={th('text-right')}>{t('transport.directions.inbound')}</th><th className={th('text-right')}>{t('transport.directions.outbound')}</th><th className={th('text-right')}>{t('transport.completedTrips')}</th><th className={th('text-right')}>{t('transport.cancelledTrips')}</th><th className={th('text-right')}>{t('transport.seatsOffered')}</th><th className={th('text-right')}>{t('transport.utilization')}</th><th className={th('text-right')}>{t('transport.noShowRate')}</th></>,
                                routeUtilization.map((r) => <tr key={r.route_id} className={tableRowClassName}><td className={td()}>{r.route_code} — {name(r)}</td><td className={num}>{r.assigned_employees}</td><td className={num}>{r.unique_riders}</td><td className={num}>{r.boardings}</td><td className={num}>{r.inbound}</td><td className={num}>{r.outbound}</td><td className={num}>{r.completed_trips}/{r.scheduled_trips}</td><td className={num}>{r.cancelled_trips}</td><td className={num}>{r.seats_offered}</td><td className={td('text-right')}><Percent value={r.utilization_percent} /></td><td className={td('text-right')}><Percent value={r.no_show_rate_percent} /></td></tr>))}
                        </TableCard>
                    ) },
                    { id: 'vehicles', label: t('transport.vehicleUtilization'), content: (
                        <TableCard title={t('transport.vehicleUtilization')} actions={exportLinks('vehicle_utilization')}>
                            {table(vehicleUtilization,
                                <><th className={th()}>{t('transport.vehicle')}</th><th className={th('text-right')}>{t('transport.capacity')}</th><th className={th('text-right')}>{t('transport.trips')}</th><th className={th('text-right')}>{t('transport.completedTrips')}</th><th className={th('text-right')}>{t('transport.passengers')}</th><th className={th('text-right')}>{t('transport.seatsOffered')}</th><th className={th('text-right')}>{t('transport.utilization')}</th><th className={th('text-right')}>{t('transport.daysOperated')}</th><th className={th('text-right')}>{t('transport.distanceKm')}</th></>,
                                vehicleUtilization.map((v) => <tr key={v.vehicle_id} className={tableRowClassName}><td className={td()}>{v.vehicle_code} · {v.plate_number}</td><td className={num}>{v.capacity ?? '—'}</td><td className={num}>{v.trips}</td><td className={num}>{v.completed_trips}</td><td className={num}>{v.passengers}</td><td className={num}>{v.seats_offered}</td><td className={td('text-right')}><Percent value={v.utilization_percent} /></td><td className={num}>{v.days_operated}</td><td className={num}>{v.distance_km}</td></tr>))}
                        </TableCard>
                    ) },
                    { id: 'providers', label: t('transport.providerPerformance'), content: (
                        <TableCard title={t('transport.providerPerformance')} description={t('transport.providerPerformanceHelp')} actions={exportLinks('provider_performance')}>
                            {table(providerPerformance,
                                <><th className={th()}>{t('transport.provider')}</th><th className={th('text-right')}>{t('transport.scheduledTrips')}</th><th className={th('text-right')}>{t('transport.completedTrips')}</th><th className={th('text-right')}>{t('transport.cancelledTrips')}</th><th className={th('text-right')}>{t('transport.completionRate')}</th><th className={th('text-right')}>{t('transport.verifiedBoardings')}</th><th className={th('text-right')}>{t('transport.uniqueRiders')}</th><th className={th('text-right')}>{t('transport.utilization')}</th><th className={th('text-right')}>{t('transport.exceptions')}</th></>,
                                providerPerformance.map((p) => <tr key={p.provider_id} className={tableRowClassName}><td className={td()}>{p.provider_code} — {name(p)}</td><td className={num}>{p.scheduled_trips}</td><td className={num}>{p.completed_trips}</td><td className={num}>{p.cancelled_trips}</td><td className={td('text-right')}><Percent value={p.completion_rate_percent} /></td><td className={num}>{p.verified_boardings}</td><td className={num}>{p.unique_riders}</td><td className={td('text-right')}><Percent value={p.utilization_percent} /></td><td className={num}>{p.exceptions}</td></tr>))}
                        </TableCard>
                    ) },
                ]} />
                <Card className="text-xs text-(--app-muted-foreground)">{t('transport.reportsFooter')}</Card>
            </div>
        </AuthenticatedLayout>
    );
}
