import { Link } from '@inertiajs/react';
import { buttonClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import ManifestTable from '@/Components/transport/ManifestTable';
import TripDispatchPanel from '@/Components/transport/TripDispatchPanel';
import { useTransportLabels } from '@/Components/transport/labels';
import { DateText } from '@/Components/transport/TransportShared';
import type { Manifest, TransportDriver, TransportTrip, TransportVehicle } from '@/types/transport';

export default function Show({ trip, manifest, vehicles, drivers, can }: { trip: TransportTrip; manifest: Manifest | null; vehicles: TransportVehicle[]; drivers: TransportDriver[]; can: { dispatch: boolean; manifest: boolean } }) {
    const { t, name } = useTransportLabels();
    const editable = trip.status === 'planned' || trip.status === 'assigned';

    return (
        <TransportProviderLayout title={`${trip.trip_number} · ${trip.route?.route_code ?? ''}`}>
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link href={route('provider.portal.transport.trips.index')} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>← {t('transport.back')}</Link>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{name(trip.route)} · <DateText value={trip.trip_date} /> · {trip.departure_time ?? ''}</p>
                    </div>
                    <div className="flex gap-2">
                        {editable && <Link href={route('provider.portal.transport.trips.edit', trip.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.edit')}</Link>}
                        {can.manifest && <a href={route('provider.portal.transport.trips.manifest.pdf', trip.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.manifestPdf')}</a>}
                    </div>
                </div>
                <TripDispatchPanel trip={trip} dispatchUrl={route('provider.portal.transport.trips.dispatch', trip.id)} vehicles={vehicles} drivers={drivers} canDispatch={can.dispatch} />
                {manifest && <ManifestTable manifest={manifest} staffTier={false} />}
            </div>
        </TransportProviderLayout>
    );
}
