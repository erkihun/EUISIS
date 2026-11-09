import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { TripForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportDriver, TransportRoute, TransportVehicle } from '@/types/transport';

export default function Create({ today, routes, vehicles, drivers }: { today: string; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.newTrip')}>
            <TripForm today={today} routes={routes} vehicles={vehicles} drivers={drivers} />
        </TransportProviderLayout>
    );
}
