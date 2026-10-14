import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { PassForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute } from '@/types/transport';

export default function Create({ today, providers, routes }: { today: string; providers: ProviderOption[]; routes: TransportRoute[] }) {
    const { t } = useLocale();
    const title = t('transport.newPass');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.passes.index')} />}>
            <Head title={title} />
            <PassForm today={today} providers={providers} routes={routes} />
        </AuthenticatedLayout>
    );
}
