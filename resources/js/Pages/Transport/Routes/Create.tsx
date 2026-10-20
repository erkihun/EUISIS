import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { RouteForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption } from '@/types/transport';

export default function Create({ providers }: { providers: ProviderOption[] }) {
    const { t } = useLocale();
    const title = t('transport.newRoute');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.routes.index')} />}>
            <Head title={title} />
            <RouteForm mode="admin" providers={providers} />
        </AuthenticatedLayout>
    );
}
