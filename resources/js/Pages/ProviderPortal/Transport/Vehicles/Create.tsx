import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { VehicleForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportRoute } from '@/types/transport';

export default function Create({ routes }: { routes: TransportRoute[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.newVehicle')}>
            <VehicleForm mode="portal" routes={routes} />
        </TransportProviderLayout>
    );
}
