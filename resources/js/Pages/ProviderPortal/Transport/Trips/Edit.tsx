import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { TripForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportDriver, TransportRoute, TransportTrip, TransportVehicle } from '@/types/transport';

export default function Edit({ trip, today, routes, vehicles, drivers }: { trip: TransportTrip; today: string; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.editTrip')}>
            <TripForm trip={trip} today={today} routes={routes} vehicles={vehicles} drivers={drivers} />
        </TransportProviderLayout>
    );
}
