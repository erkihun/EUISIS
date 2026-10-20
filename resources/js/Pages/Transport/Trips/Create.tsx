import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { AdminTripForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportDriver, TransportRoute, TransportVehicle } from '@/types/transport';

export default function Create(props: { today: string; providers: ProviderOption[]; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[] }) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.planExtraTrip')} backHref={route('transport.trips.index')} />}>
            <Head title={t('transport.planExtraTrip')} />
            <AdminTripForm {...props} />
        </AuthenticatedLayout>
    );
}
