import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportVehicleAdminForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';
export default function Create(props: any) { const { t } = useLocale(); return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.newVehicle')}</h1><TransportVehicleAdminForm {...props} /></AuthenticatedLayout>; }
