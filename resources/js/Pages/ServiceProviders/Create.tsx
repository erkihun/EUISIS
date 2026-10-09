import { ChevronRight } from '@/Components/Icons';
import ProviderForm, { type ProviderFormData } from '@/Components/ServiceProviders/ProviderForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
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
        <AuthenticatedLayout>
            <Head title={t('providers.createProvider')} />

            <div className="space-y-6">
                <header className="space-y-3.5">
                    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[13px] text-[color:var(--app-muted-foreground)]">
                        <Link href={route('service-providers.index')} className="hover:text-[color:var(--app-foreground)]">{t('providers.title')}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <span aria-current="page" className="font-medium text-[color:var(--app-foreground)]">{t('providers.addProvider')}</span>
                    </nav>
                    <div>
                        <h1 className="text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{t('providers.createProvider')}</h1>
                        <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('providers.formSubtitle')}</p>
                    </div>
                </header>

                <ProviderForm
                    form={form}
                    serviceTypes={serviceTypes}
                    organizations={organizations}
                    submitLabel={t('providers.saveProvider')}
                    cancelHref={route('service-providers.index')}
                    onSubmit={() => form.post(route('service-providers.store'))}
                />
            </div>
        </AuthenticatedLayout>
    );
}
