import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';

export default function Scan({ routes = [], trips = [] }: { routes: any[]; trips: any[] }) {
    return <TransportProviderLayout title="Scan ID"><TransportScanPanel routes={routes} trips={trips} /></TransportProviderLayout>;
}
