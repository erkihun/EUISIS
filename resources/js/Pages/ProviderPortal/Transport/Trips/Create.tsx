import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import TransportTripForm from '@/Components/transport/TransportTripForm';
export default function Create(props: any) { return <TransportProviderLayout title="New Trip"><TransportTripForm {...props} /></TransportProviderLayout>; }
