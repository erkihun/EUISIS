import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import ManifestTable from '@/Components/transport/ManifestTable';
import TripDispatchPanel from '@/Components/transport/TripDispatchPanel';
import { useTransportLabels } from '@/Components/transport/labels';
import { DateText, Pill } from '@/Components/transport/TransportShared';
import type { Manifest, TransportDriver, TransportException, TransportTrip, TransportVehicle } from '@/types/transport';

export default function Show({ trip, manifest, exceptions, vehicles, drivers, can }: { trip: TransportTrip; manifest: Manifest | null; exceptions: TransportException[]; vehicles: TransportVehicle[]; drivers: TransportDriver[]; can: { dispatch: boolean; manifest: boolean; void: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const title = `${trip.trip_number} · ${trip.route?.route_code ?? ''}`;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={`${name(trip.route)} · ${name(trip.provider)}`} backHref={route('transport.trips.index')}
            actions={can.manifest ? <a href={route('transport.trips.manifest.pdf', trip.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.manifestPdf')}</a> : undefined} />}>
            <Head title={title} />
            <div className="space-y-6">
                <p className="text-sm text-(--app-muted-foreground)"><DateText value={trip.trip_date} /> · {trip.departure_time ?? ''}{trip.actual_started_at ? ` · ${t('transport.startedAt')} ` : ''}{trip.actual_started_at && <DateText value={trip.actual_started_at} withTime />}{trip.actual_ended_at ? ` · ${t('transport.endedAt')} ` : ''}{trip.actual_ended_at && <DateText value={trip.actual_ended_at} withTime />}{trip.is_extra ? ` · ${t('transport.extraTrip')}` : ''}</p>
                <TripDispatchPanel trip={trip} dispatchUrl={route('transport.trips.dispatch', trip.id)} vehicles={vehicles} drivers={drivers} canDispatch={can.dispatch} />
                {manifest && <ManifestTable manifest={manifest} staffTier voidUrl={can.void ? (row) => route('transport.transactions.void', row.id) : undefined} />}
                {exceptions.length > 0 && (
                    <TableCard title={t('transport.exceptions')}>
                        <table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.occurredAt')}</th><th className={tableHeaderCellClassName}>{t('transport.type')}</th><th className={tableHeaderCellClassName}>{t('transport.severity')}</th><th className={tableHeaderCellClassName}>{t('transport.employee')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{exceptions.map((e) => <tr key={e.id} className={tableRowClassName}><td className={tableCellClassName}><DateText value={e.occurred_at} withTime /></td><td className={tableCellClassName}>{label('exceptionTypes', e.type)}</td><td className={tableCellClassName}><Pill group="severities" value={e.severity} /></td><td className={tableCellClassName}>{e.employee?.full_name ?? '—'}</td><td className={tableCellClassName}><Pill value={e.status} /></td></tr>)}</tbody></table>
                    </TableCard>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
