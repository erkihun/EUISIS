import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ScheduleForm } from '@/Components/transport/TransportVerifiedForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportDriver, TransportRoute, TransportSchedule, TransportVehicle } from '@/types/transport';

export default function Edit(props: { schedule: TransportSchedule; today: string; providers: ProviderOption[]; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[] }) {
    const { t } = useLocale();
    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.editSchedule')} backHref={route('transport.schedules.index')} />}>
            <Head title={t('transport.editSchedule')} />
            <ScheduleForm {...props} urls={{ store: route('transport.schedules.store'), update: (id) => route('transport.schedules.update', id), cancel: route('transport.schedules.index') }} />
        </AuthenticatedLayout>
    );
}
