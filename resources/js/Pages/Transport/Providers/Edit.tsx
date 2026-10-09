import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportProviderForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';

export default function Edit({ provider, organizations = [] }: { provider: any; organizations: any[] }) {
    const { t } = useLocale();

    return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.editProvider')}</h1><TransportProviderForm provider={provider} organizations={organizations} /></AuthenticatedLayout>;
}
