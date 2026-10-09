import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportDriverForm from '@/Components/transport/TransportDriverForm';
export default function Edit({ driver, vehicles = [] }: { driver: any; vehicles: any[] }) { return <TransportProviderLayout title="Edit Driver"><TransportDriverForm driver={driver} vehicles={vehicles} /></TransportProviderLayout>; }
