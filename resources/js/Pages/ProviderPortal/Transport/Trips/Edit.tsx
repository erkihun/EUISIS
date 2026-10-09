import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportTripForm from '@/Components/transport/TransportTripForm';
export default function Edit(props: any) { return <TransportProviderLayout title="Edit Trip"><TransportTripForm {...props} /></TransportProviderLayout>; }
