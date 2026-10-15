import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { RouteForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute } from '@/types/transport';

export default function Edit({ route: record, providers }: { route: TransportRoute; providers: ProviderOption[] }) {
    const { t } = useLocale();
    const title = t('transport.editRoute');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.routes.index')} />}>
            <Head title={title} />
            <RouteForm mode="admin" route={record} providers={providers} />
        </AuthenticatedLayout>
    );
}
