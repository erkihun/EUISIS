import { Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Input, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Kpi, Percent, Pill, queryWith } from '@/Components/transport/TransportShared';
import { TRIP_STATUSES, type Paginated, type TransportTrip } from '@/types/transport';

const helper = createColumnHelper<TransportTrip>();

/** The day's trip board: what is planned, boarding, on the road and done, each opening its dispatch and manifest. */
export default function Index({ trips, filters, today }: { trips: Paginated<TransportTrip>; filters: { date: string; status?: string }; today: string }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ date: filters.date ?? today, status: filters.status ?? '' });
    const apply = (next = draft) => router.get(route('provider.portal.transport.trips.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });
    const rows = trips.data;
    const count = (s: string[]) => rows.filter((x) => s.includes(x.status)).length;

    const columns = [
        helper.accessor('departure_time', { header: t('transport.departure'), cell: (c) => <Link href={route('provider.portal.transport.trips.show', c.row.original.id)} className="font-medium tabular-nums underline-offset-2 hover:underline">{c.getValue() ?? '—'}<span className="block text-xs font-normal text-(--app-muted-foreground)">{c.row.original.trip_number}</span></Link> }),
        helper.display({ id: 'route', header: t('transport.route'), cell: (c) => <div>{c.row.original.route?.route_code} — {name(c.row.original.route)}<div className="text-xs text-(--app-muted-foreground)">{label('directions', c.row.original.direction)}</div></div> }),
        helper.display({ id: 'vehicle', header: t('transport.vehicle'), cell: (c) => [c.row.original.vehicle?.plate_number, c.row.original.driver?.full_name].filter(Boolean).join(' · ') || '—' }),
        helper.display({ id: 'boarded', header: t('transport.boarded'), cell: (c) => <span className="tabular-nums">{c.row.original.boarded_count}{c.row.original.effective_capacity ? ` / ${c.row.original.effective_capacity}` : ''} <Percent value={c.row.original.utilization_percent} /></span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportTrip, unknown>[];

    return (
        <TransportProviderLayout title={t('transport.trips')}>
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-(--app-muted-foreground)"><DateText value={filters.date} /></p>
                    <Link href={route('provider.portal.transport.trips.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.newTrip')}</Link>
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Kpi label={t('transport.statuses.planned')} value={count(['planned', 'assigned'])} />
                    <Kpi label={t('transport.statuses.boarding')} value={count(['boarding'])} />
                    <Kpi label={t('transport.statuses.in_progress')} value={count(['in_progress'])} />
                    <Kpi label={t('transport.statuses.completed')} value={count(['completed'])} hint={`${count(['cancelled', 'failed'])} ${t('transport.statuses.cancelled')}`} />
                </div>
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ date: today, status: '' })} hasActiveFilters={Boolean(filters.status) || filters.date !== today}>
                    <FilterField label={t('transport.tripDate')}><Input type="date" value={draft.date} onChange={(e) => setDraft({ ...draft, date: e.target.value })} /></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{TRIP_STATUSES.map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={rows} columns={columns} emptyTitle={t('transport.noTrips')} pagination={uiMeta(trips.meta)} onPageChange={(page) => router.get(route('provider.portal.transport.trips.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </TransportProviderLayout>
    );
}
