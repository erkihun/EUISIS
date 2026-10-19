import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { RouteForm } from '@/Components/transport/TransportForms';
import StopsEditor from '@/Components/transport/StopsEditor';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute, TransportRouteStop } from '@/types/transport';

export default function Edit({ route: record, providers, stops }: { route: TransportRoute; providers: ProviderOption[]; stops: TransportRouteStop[] }) {
    const { t } = useLocale();
    const title = t('transport.editRoute');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('transport.routes.index')} />}>
            <Head title={title} />
            <div className="space-y-6">
                <RouteForm mode="admin" route={record} providers={providers} />
                <StopsEditor stops={stops} canEdit storeUrl={route('transport.routes.stops.store', record.id)} updateUrl={(stop) => route('transport.routes.stops.update', [record.id, stop.id])} destroyUrl={(stop) => route('transport.routes.stops.destroy', [record.id, stop.id])} />
            </div>
        </AuthenticatedLayout>
    );
}
