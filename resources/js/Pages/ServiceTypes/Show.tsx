import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { buttonClassName } from '@/Components/ui';
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
    const { confirm } = useConfirm();

    // The same dialog the list page shows: a delete is never one accidental click.
    async function archive() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('service-types.archive', serviceType.id));
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    backHref={route('service-types.index')}
                    title={serviceType.name_en}
                    actions={(
                        <div className="flex gap-3">
                            {serviceType.can.update && (
                                <Link href={route('service-types.edit', serviceType.id)} className={buttonClassName({ variant: 'primary' })}>
                                    {t('common.edit')}
                                </Link>
                            )}
                            {serviceType.can.archive && serviceType.is_active && (
                                <button type="button" onClick={archive} className={buttonClassName({ variant: 'destructive' })}>
                                    {t('common.delete')}
                                </button>
                            )}
                            {serviceType.can.restore && !serviceType.is_active && (
                                <button type="button" onClick={() => router.post(route('service-types.restore', serviceType.id))} className={buttonClassName({ variant: 'primary' })}>
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
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6 lg:col-span-2">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.code')}</p>
                            <p className="mt-1 font-mono text-sm text-(--app-foreground)">{serviceType.code}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-(--app-muted-foreground)">{t('common.status')}</p>
                            <div className="mt-1">
                                <StatusBadge status={serviceType.is_active ? 'active' : 'inactive'} />
                            </div>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.englishName')}</p>
                            <p className="mt-1 text-sm text-(--app-foreground)">{serviceType.name_en}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.amharicName')}</p>
                            <p className="mt-1 text-sm text-(--app-foreground)">{serviceType.name_am ?? '—'}</p>
                        </div>
                    </div>
                    <div className="mt-6">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.description')}</p>
                        <p className="mt-1 text-sm leading-6 text-(--app-foreground)">{serviceType.description ?? '—'}</p>
                    </div>
                </section>

                <aside className="space-y-4">
                    <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.providersCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-(--app-foreground)">{serviceType.providers_count ?? 0}</p>
                    </div>
                    <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.rulesCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-(--app-foreground)">{serviceType.rules_count ?? 0}</p>
                    </div>
                    <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceTypes.entitlementsCount')}</p>
                        <p className="mt-1 text-2xl font-semibold text-(--app-foreground)">{serviceType.entitlements_count ?? 0}</p>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
