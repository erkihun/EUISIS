import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { VehicleForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute, TransportVehicle } from '@/types/transport';

export default function Edit({ vehicle, providers, routes }: { vehicle: TransportVehicle; providers: ProviderOption[]; routes: TransportRoute[] }) {
    const { t } = useLocale();
    const title = t('transport.editVehicle');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.vehicles.index')} />}>
            <Head title={title} />
            <VehicleForm mode="admin" vehicle={vehicle} providers={providers} routes={routes} />
        </AuthenticatedLayout>
    );
}
