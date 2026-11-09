import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { DriverForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportDriver, TransportVehicle } from '@/types/transport';

export default function Edit({ driver, providers, vehicles }: { driver: TransportDriver; providers: ProviderOption[]; vehicles: TransportVehicle[] }) {
    const { t } = useLocale();
    const title = t('transport.editDriver');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.drivers.index')} />}>
            <Head title={title} />
            <DriverForm mode="admin" driver={driver} providers={providers} vehicles={vehicles} />
        </AuthenticatedLayout>
    );
}
