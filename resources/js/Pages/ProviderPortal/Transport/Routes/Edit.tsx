import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportRouteForm from '@/Components/transport/TransportRouteForm';
export default function Edit({ route: routeData }: { route: any }) { return <TransportProviderLayout title="Edit Route"><TransportRouteForm routeData={routeData} /></TransportProviderLayout>; }
