import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, TransportRoute, TransportRouteStop, TransportTrip } from '@/types/transport';
import type { TerminalConfig } from '@/Components/transport/TransportScanPanel';

export default function Scan({ providers, routes, trips, stops, terminal }: { providers: ProviderOption[]; routes: TransportRoute[]; trips: TransportTrip[]; stops: TransportRouteStop[]; terminal: TerminalConfig }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.scan_id')} description={t('transport.scanIntro')} />}>
            <Head title={t('transport.scan_id')} />
            <TransportScanPanel providers={providers} routes={routes} trips={trips} stops={stops} terminal={terminal} scanRouteName="transport.scan.store" lookupRouteName="transport.scan.lookup" dispatchRouteName="transport.trips.dispatch" />
        </AuthenticatedLayout>
    );
}
