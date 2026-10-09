import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportDriverForm from '@/Components/transport/TransportDriverForm';
export default function Create({ vehicles = [] }: { vehicles: any[] }) { return <TransportProviderLayout title="New Driver"><TransportDriverForm vehicles={vehicles} /></TransportProviderLayout>; }
