import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { RouteForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';
import type { TransportRoute } from '@/types/transport';

export default function Edit({ route: record }: { route: TransportRoute }) {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.editRoute')}>
            <RouteForm mode="portal" route={record} />
        </TransportProviderLayout>
    );
}
