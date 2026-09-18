import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type ServiceTypeOption = { id: string; name_en: string };
type OrganizationOption = { id: string; name_en: string };

export default function ServiceProvidersCreate({
    serviceTypes,
    organizations,
}: {
    serviceTypes: ServiceTypeOption[];
    organizations: OrganizationOption[];
}) {
    const { t } = useLocale();

    const form = useForm({
        name: '',
        code: '',
        service_type_id: serviceTypes[0]?.id ?? '',
        organization_id: '',
        status: 'active',
        is_demo: false,
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    const labelCls = 'mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400';

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('service-providers.store'));
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('providers.createProvider')} description="" />}
        >
            <Head title={t('providers.createProvider')} />

            <div className="mx-auto max-w-xl">
                <div className="rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submit} className="space-y-4">
                        {/* Name */}
                        <div>
                            <label className={labelCls}>{t('providers.name')}</label>
                            <input
                                type="text"
                                className={inputCls}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            {form.errors.name && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>
                            )}
                        </div>

                        {/* Code */}
                        <div>
                            <label className={labelCls}>{t('providers.code')}</label>
                            <input
                                type="text"
                                className={inputCls}
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder={t('providers.codePlaceholder')}
                                required
                            />
                            {form.errors.code && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.code}</p>
                            )}
                        </div>

                        {/* Service Type */}
                        <div>
                            <label className={labelCls}>{t('providers.serviceType')}</label>
                            <select
                                className={inputCls}
                                value={form.data.service_type_id}
                                onChange={(e) => form.setData('service_type_id', e.target.value)}
                                required
                            >
                                {serviceTypes.map((st) => (
                                    <option key={st.id} value={st.id}>{st.name_en}</option>
                                ))}
                            </select>
                            {form.errors.service_type_id && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.service_type_id}</p>
                            )}
                        </div>

                        {/* Organization */}
                        <div>
                            <label className={labelCls}>{t('providers.organizationOptional')}</label>
                            <select
                                className={inputCls}
                                value={form.data.organization_id}
                                onChange={(e) => form.setData('organization_id', e.target.value)}
                            >
                                <option value="">{t('providers.noOrgRestriction')}</option>
                                {organizations.map((org) => (
                                    <option key={org.id} value={org.id}>{org.name_en}</option>
                                ))}
                            </select>
                        </div>

                        {/* Status */}
                        <div>
                            <label className={labelCls}>{t('providers.statusLabel')}</label>
                            <select
                                className={inputCls}
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                            >
                                <option value="active">{t('providers.statusActive')}</option>
                                <option value="inactive">{t('providers.statusInactive')}</option>
                                <option value="suspended">{t('providers.statusSuspended')}</option>
                            </select>
                        </div>

                        {/* Is Demo */}
                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                checked={form.data.is_demo}
                                onChange={(e) => form.setData('is_demo', e.target.checked)}
                            />
                            {t('providers.isDemo')}
                        </label>

                        <div className="pt-2">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] disabled:opacity-60"
                            >
                                {form.processing ? t('providers.saving') : t('providers.save')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
