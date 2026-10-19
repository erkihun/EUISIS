import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { PassForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute, TransportRouteStop } from '@/types/transport';

export default function Create({ today, providers, routes, stops }: { today: string; providers: ProviderOption[]; routes: TransportRoute[]; stops: TransportRouteStop[] }) {
    const { t } = useLocale();
    const title = t('transport.newPass');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.passes.index')} />}>
            <Head title={title} />
            <PassForm today={today} providers={providers} routes={routes} stops={stops} />
        </AuthenticatedLayout>
    );
}
