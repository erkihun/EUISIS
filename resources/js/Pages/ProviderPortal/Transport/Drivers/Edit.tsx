import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { DriverForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportDriver, TransportVehicle } from '@/types/transport';

export default function Edit({ driver, vehicles }: { driver: TransportDriver; vehicles: TransportVehicle[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.editDriver')}>
            <DriverForm mode="portal" driver={driver} vehicles={vehicles} />
        </TransportProviderLayout>
    );
}
