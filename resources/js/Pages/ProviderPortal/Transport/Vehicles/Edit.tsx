import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportVehicleForm from '@/Components/transport/TransportVehicleForm';
export default function Edit({ vehicle, routes = [] }: { vehicle: any; routes: any[] }) { return <TransportProviderLayout title="Edit Vehicle"><TransportVehicleForm vehicle={vehicle} routes={routes} /></TransportProviderLayout>; }
