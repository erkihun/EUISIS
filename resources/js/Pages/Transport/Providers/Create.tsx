import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportProviderForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';

export default function Create({ organizations = [] }: { organizations: any[] }) {
    const { t } = useLocale();

    return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.registerProvider')}</h1><TransportProviderForm organizations={organizations} /></AuthenticatedLayout>;
}
