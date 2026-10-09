import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportVehicleForm from '@/Components/transport/TransportVehicleForm';
export default function Create({ routes = [] }: { routes: any[] }) { return <TransportProviderLayout title="New Vehicle"><TransportVehicleForm routes={routes} /></TransportProviderLayout>; }
