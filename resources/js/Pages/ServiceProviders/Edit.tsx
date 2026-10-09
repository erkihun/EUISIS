import { ChevronRight } from '@/Components/Icons';
import ProviderForm, { type ProviderFormData } from '@/Components/ServiceProviders/ProviderForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
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
        <AuthenticatedLayout>
            <Head title={t('providers.editProvider')} />

            <div className="space-y-6">
                <header className="space-y-3.5">
                    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[13px] text-[color:var(--app-muted-foreground)]">
                        <Link href={route('service-providers.index')} className="hover:text-[color:var(--app-foreground)]">{t('providers.title')}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <Link href={showHref} className="hover:text-[color:var(--app-foreground)]">{provider.name}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <span aria-current="page" className="font-medium text-[color:var(--app-foreground)]">{t('providers.edit')}</span>
                    </nav>
                    <div>
                        <h1 className="text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{t('providers.editProvider')}</h1>
                        <p className="mt-1 font-mono text-sm text-[color:var(--app-muted-foreground)]">{provider.code}</p>
                    </div>
                </header>

                <ProviderForm
                    form={form}
                    serviceTypes={serviceTypes}
                    organizations={organizations}
                    submitLabel={t('providers.saveChanges')}
                    cancelHref={showHref}
                    onSubmit={() => form.patch(route('service-providers.update', provider.id))}
                />
            </div>
        </AuthenticatedLayout>
    );
}
