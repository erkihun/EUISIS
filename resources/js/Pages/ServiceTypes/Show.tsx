import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

export default function ServiceTypesShow({
    serviceType,
}: {
    serviceType: {
        id: string;
        code: string;
        name_en: string;
        name_am: string | null;
        description: string | null;
        is_active: boolean;
        providers_count: number | null;
        rules_count: number | null;
        entitlements_count: number | null;
        can: { update: boolean; archive: boolean; restore: boolean };
    };
}) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    backHref={route('service-types.index')}
                    title={serviceType.name_en}
                    actions={(
                        <div className="flex gap-3">
                            {serviceType.can.update && (
                                <Link href={route('service-types.edit', serviceType.id)} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                                    {t('common.edit')}
                                </Link>
                            )}
                            {serviceType.can.archive && serviceType.is_active && (
                                <button type="button" onClick={() => router.delete(route('service-types.archive', serviceType.id))} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    {t('common.delete')}
                                </button>
                            )}
                            {serviceType.can.restore && !serviceType.is_active && (
                                <button type="button" onClick={() => router.post(route('service-types.restore', serviceType.id))} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                    {t('common.restore')}
                                </button>
                            )}
                        </div>
                    )}
                />
            )}
        >
            <Head title={serviceType.name_en} />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-panel border border-gray-200 bg-white p-6 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.code')}</p>
                            <p className="mt-1 font-mono text-sm text-gray-900 dark:text-slate-100">{serviceType.code}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.status')}</p>
                            <div className="mt-1">
                                <StatusBadge status={serviceType.is_active ? 'active' : 'inactive'} />
                            </div>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.englishName')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{serviceType.name_en}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.amharicName')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{serviceType.name_am ?? '—'}</p>
                        </div>
                    </div>
                    <div className="mt-6">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.description')}</p>
                        <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-slate-300">{serviceType.description ?? '—'}</p>
                    </div>
                </section>

                <aside className="space-y-4">
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.providersCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{serviceType.providers_count ?? 0}</p>
                    </div>
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.rulesCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{serviceType.rules_count ?? 0}</p>
                    </div>
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('serviceTypes.entitlementsCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{serviceType.entitlements_count ?? 0}</p>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
