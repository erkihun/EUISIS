import PageHeader from '@/Components/PageHeader';
import ProviderForm, { type ProviderFormData } from '@/Components/ServiceProviders/ProviderForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import type { JSX } from 'react';

type Option = { id: string; code?: string; name_en: string; name_am?: string | null };

export default function ServiceProvidersCreate({ serviceTypes, organizations }: { serviceTypes: Option[]; organizations: Option[] }): JSX.Element {
    const { t } = useLocale();

    const form = useForm<ProviderFormData>({
        name: '',
        code: '',
        service_type_id: serviceTypes[0]?.id ?? '',
        organization_id: '',
        status: 'active',
        is_demo: false,
    });

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('providers.createProvider')}
                    description={t('providers.formSubtitle')}
                    backHref={route('service-providers.index')}
                />
            }
        >
            <Head title={t('providers.createProvider')} />

            <ProviderForm
                form={form}
                serviceTypes={serviceTypes}
                organizations={organizations}
                submitLabel={t('providers.saveProvider')}
                cancelHref={route('service-providers.index')}
                onSubmit={() => form.post(route('service-providers.store'))}
            />
        </AuthenticatedLayout>
    );
}
