import PageHeader from '@/Components/PageHeader';
import ProviderForm, { type ProviderFormData } from '@/Components/ServiceProviders/ProviderForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import type { JSX } from 'react';

type Option = { id: string; code?: string; name_en: string; name_am?: string | null };

type Provider = {
    id: string;
    name: string;
    code: string;
    status: string;
    is_demo: boolean;
    service_type_id?: string | null;
    organization_id?: string | null;
};

export default function ServiceProvidersEdit({
    provider,
    serviceTypes,
    organizations,
}: {
    provider: Provider;
    serviceTypes: Option[];
    organizations: Option[];
}): JSX.Element {
    const { t } = useLocale();

    const form = useForm<ProviderFormData>({
        name: provider.name,
        code: provider.code,
        service_type_id: provider.service_type_id ?? serviceTypes[0]?.id ?? '',
        organization_id: provider.organization_id ?? '',
        status: provider.status,
        is_demo: provider.is_demo,
    });

    const showHref = route('service-providers.show', provider.id);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('providers.editProvider')}
                    description={provider.code}
                    backHref={showHref}
                />
            }
        >
            <Head title={t('providers.editProvider')} />

            <ProviderForm
                form={form}
                serviceTypes={serviceTypes}
                organizations={organizations}
                submitLabel={t('providers.saveChanges')}
                cancelHref={showHref}
                onSubmit={() => form.patch(route('service-providers.update', provider.id))}
            />
        </AuthenticatedLayout>
    );
}
