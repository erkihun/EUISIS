import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { DriverForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportVehicle } from '@/types/transport';

export default function Create({ vehicles }: { vehicles: TransportVehicle[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.newDriver')}>
            <DriverForm mode="portal" vehicles={vehicles} />
        </TransportProviderLayout>
    );
}
