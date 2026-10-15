import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute, TransportTrip } from '@/types/transport';

export default function Scan({ providers, routes, trips }: { providers: ProviderOption[]; routes: TransportRoute[]; trips: TransportTrip[] }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.scan_id')} description={t('transport.scanIntro')} />}>
            <Head title={t('transport.scan_id')} />
            <TransportScanPanel providers={providers} routes={routes} trips={trips} scanRouteName="transport.scan.store" />
        </AuthenticatedLayout>
    );
}
