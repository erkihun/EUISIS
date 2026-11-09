import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ProviderForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportProvider } from '@/types/transport';

export default function Edit({ provider }: { provider: TransportProvider }) {
    const { t } = useLocale();
    const title = t('transport.editProvider');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.providers.show', provider.id)} />}>
            <Head title={title} />
            <ProviderForm provider={provider} />
        </AuthenticatedLayout>
    );
}
