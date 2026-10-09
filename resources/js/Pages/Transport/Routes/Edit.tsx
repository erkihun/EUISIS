import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportRouteAdminForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';
export default function Edit({ route: routeData, providers = [] }: { route: any; providers: any[] }) { const { t } = useLocale(); return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.editRoute')}</h1><TransportRouteAdminForm routeData={routeData} providers={providers} /></AuthenticatedLayout>; }
