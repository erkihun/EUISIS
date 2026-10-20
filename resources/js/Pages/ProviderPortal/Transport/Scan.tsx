import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';
import { useLocale } from '@/hooks/useLocale';
import type { TransportRoute, TransportRouteStop, TransportTrip } from '@/types/transport';
import type { TerminalConfig } from '@/Components/transport/TransportScanPanel';

export default function Scan({ routes, trips, stops, terminal }: { routes: TransportRoute[]; trips: TransportTrip[]; stops: TransportRouteStop[]; terminal: TerminalConfig }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.scan_id')}>
            <TransportScanPanel routes={routes} trips={trips} stops={stops} terminal={terminal} scanRouteName="provider.portal.transport.scan.store" lookupRouteName="provider.portal.transport.scan.lookup" dispatchRouteName="provider.portal.transport.trips.dispatch" />
        </TransportProviderLayout>
    );
}
