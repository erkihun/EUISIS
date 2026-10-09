import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TransportDriverAdminForm } from '@/Components/transport/TransportAdminForms';
import { useLocale } from '@/hooks/useLocale';
export default function Edit(props: any) { const { t } = useLocale(); return <AuthenticatedLayout><h1 className="mb-4 text-lg font-semibold">{t('transport.editDriver')}</h1><TransportDriverAdminForm {...props} /></AuthenticatedLayout>; }
