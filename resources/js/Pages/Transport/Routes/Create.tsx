import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportRouteAdminForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';
export default function Create({ providers = [] }: { providers: any[] }) { const { t } = useLocale(); return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.newRoute')}</h1><TransportRouteAdminForm providers={providers} /></AuthenticatedLayout>; }
