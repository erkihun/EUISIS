import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ProviderForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';

export default function Create() {
    const { t } = useLocale();
    const title = t('transport.registerProvider');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.providers.index')} />}>
            <Head title={title} />
            <ProviderForm />
        </AuthenticatedLayout>
    );
}
