import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { DeviceForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportVehicle } from '@/types/transport';

export default function Create(props: { providers: ProviderOption[]; vehicles: TransportVehicle[] }) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.registerDevice')} backHref={route('transport.devices.index')} />}>
            <Head title={t('transport.registerDevice')} />
            <DeviceForm {...props} />
        </AuthenticatedLayout>
    );
}
