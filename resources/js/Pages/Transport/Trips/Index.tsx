import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Input, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Percent, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportTrip } from '@/types/transport';

const helper = createColumnHelper<TransportTrip>();

export default function Index({ trips, filters, providers, statuses, can }: { trips: Paginated<TransportTrip>; filters: Record<string, string | undefined>; providers: ProviderOption[]; statuses: string[]; can: { create: boolean; dispatch: boolean; manifest: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ date: filters.date ?? '', provider_id: filters.provider_id ?? '', status: filters.status ?? '', direction: filters.direction ?? '' });
    const apply = (next = draft) => router.get(route('transport.trips.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.accessor('trip_number', { header: t('transport.trip'), cell: (c) => <Link href={route('transport.trips.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()}</Link> }),
        helper.display({ id: 'when', header: t('transport.tripDate'), cell: (c) => <span className="whitespace-nowrap"><DateText value={c.row.original.trip_date} /> <span className="tabular-nums text-(--app-muted-foreground)">{c.row.original.departure_time ?? ''}</span></span> }),
        helper.display({ id: 'route', header: t('transport.route'), cell: (c) => <div><div>{c.row.original.route?.route_code} — {name(c.row.original.route)}</div><div className="text-xs text-(--app-muted-foreground)">{name(c.row.original.provider)} · {label('directions', c.row.original.direction)}</div></div> }),
        helper.display({ id: 'vehicle', header: t('transport.vehicle'), cell: (c) => c.row.original.vehicle?.plate_number ?? '—' }),
        helper.display({ id: 'boarded', header: t('transport.boarded'), cell: (c) => <span className="tabular-nums">{c.row.original.boarded_count}{c.row.original.effective_capacity ? ` / ${c.row.original.effective_capacity}` : ''} <Percent value={c.row.original.utilization_percent} /></span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportTrip, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.trips')} description={t('transport.tripsIntro')} actions={can.create ? <Link href={route('transport.trips.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.planExtraTrip')}</Link> : undefined} />}>
            <Head title={t('transport.trips')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ date: '', provider_id: '', status: '', direction: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.status || filters.direction)}>
                    <FilterField label={t('transport.tripDate')}><Input type="date" value={draft.date} onChange={(e) => setDraft({ ...draft, date: e.target.value })} /></FilterField>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.direction')}><Select value={draft.direction} onChange={(e) => setDraft({ ...draft, direction: e.target.value })}><option value="">{t('transport.all')}</option><option value="inbound">{label('directions', 'inbound')}</option><option value="outbound">{label('directions', 'outbound')}</option></Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{statuses.map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={trips.data} columns={columns} emptyTitle={t('transport.noTrips')} pagination={uiMeta(trips.meta)} onPageChange={(page) => router.get(route('transport.trips.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
