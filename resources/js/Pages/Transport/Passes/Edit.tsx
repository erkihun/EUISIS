import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { PassForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportPass, TransportRoute } from '@/types/transport';

export default function Edit({ pass, today, providers, routes }: { pass: TransportPass; today: string; providers: ProviderOption[]; routes: TransportRoute[] }) {
    const { t } = useLocale();
    const title = t('transport.editPass');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.passes.index')} />}>
            <Head title={title} />
            <PassForm pass={pass} today={today} providers={providers} routes={routes} />
        </AuthenticatedLayout>
    );
}
