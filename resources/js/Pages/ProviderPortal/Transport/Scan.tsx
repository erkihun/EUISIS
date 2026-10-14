import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';
import { useLocale } from '@/hooks/useLocale';
import type { TransportRoute, TransportTrip } from '@/types/transport';

export default function Scan({ routes, trips }: { routes: TransportRoute[]; trips: TransportTrip[] }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.scan_id')}>
            <TransportScanPanel routes={routes} trips={trips} scanRouteName="provider.portal.transport.scan.store" />
        </TransportProviderLayout>
    );
}
