import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { RouteForm } from '@/Components/transport/TransportForms';
import { useLocale } from '@/hooks/useLocale';

export default function Create() {
    const { t } = useLocale();

    return (
        <TransportProviderLayout title={t('transport.newRoute')}>
            <RouteForm mode="portal" />
        </TransportProviderLayout>
    );
}
