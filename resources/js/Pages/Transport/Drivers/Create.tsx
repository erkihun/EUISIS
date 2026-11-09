import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { DriverForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportVehicle } from '@/types/transport';

export default function Create({ providers, vehicles }: { providers: ProviderOption[]; vehicles: TransportVehicle[] }) {
    const { t } = useLocale();
    const title = t('transport.newDriver');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.drivers.index')} />}>
            <Head title={title} />
            <DriverForm mode="admin" providers={providers} vehicles={vehicles} />
        </AuthenticatedLayout>
    );
}
