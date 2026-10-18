import { router } from '@inertiajs/react';
import { Pagination, StatusBadge } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import PortalList from '@/Components/transport/PortalList';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import type { Paginated, TransportTrip } from '@/types/transport';

export default function Index({ trips }: { trips: Paginated<TransportTrip> }) {
    const { t, name, label, tone } = useTransportLabels();

    return (
        <TransportProviderLayout title={t('transport.trips')}>
            <PortalList
                rows={trips.data}
                createHref={route('provider.portal.transport.trips.create')}
                createLabel={t('transport.newTrip')}
                editHref={(trip) => route('provider.portal.transport.trips.edit', trip.id)}
                footer={<Pagination meta={uiMeta(trips.meta)} onPageChange={(page) => router.get(route('provider.portal.transport.trips.index'), { page }, { preserveScroll: true })} />}
                columns={[
                    { header: t('transport.tripDate'), cell: (trip) => <><div className="font-medium tabular-nums">{trip.trip_date} {trip.departure_time}</div><div className="text-xs text-(--app-muted-foreground)">{trip.trip_number}</div></> },
                    { header: t('transport.route'), cell: (trip) => (trip.route ? `${trip.route.route_code} — ${name(trip.route)}` : '—') },
                    { header: t('transport.vehicle'), cell: (trip) => [trip.vehicle?.plate_number, trip.driver?.full_name].filter(Boolean).join(' · ') || '—' },
                    { header: t('transport.boarded'), cell: (trip) => `${trip.boarded_count}${trip.capacity ? ` / ${trip.capacity}` : ''}`, className: 'tabular-nums' },
                    { header: t('transport.status'), cell: (trip) => <StatusBadge tone={tone(trip.status)}>{label('statuses', trip.status)}</StatusBadge> },
                ]}
            />
        </TransportProviderLayout>
    );
}
