import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import IsicActivityLevelBadge from '@/Components/isic-activities/IsicActivityLevelBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

export default function IsicActivitiesShow({ isicActivity }: { isicActivity: any }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function handleArchive() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('isic-activities.archive', isicActivity.id));
    }

    async function handleRestore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('isic-activities.restore', isicActivity.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('isic-activities.index')}
                    title={`${isicActivity.isic_code} · ${isicActivity.name_en ?? isicActivity.name_am ?? ''}`}
                    actions={
                        <div className="flex gap-3">
                            {isicActivity.can?.update && (
                                <Link
                                    href={route('isic-activities.edit', isicActivity.id)}
                                    className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {isicActivity.can?.archive && !isicActivity.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                                >
                                    {t('isicActivities.archiveIsicActivity')}
                                </button>
                            )}
                            {isicActivity.can?.restore && isicActivity.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                                >
                                    {t('isicActivities.restoreIsicActivity')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={isicActivity.name_en ?? isicActivity.isic_code} />
            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2 text-sm">
                        <Field label={t('isicActivities.isicCode')} value={isicActivity.isic_code} mono />
                        <Field
                            label={t('isicActivities.activityLevel')}
                            value={<IsicActivityLevelBadge level={isicActivity.level} />}
                        />
                        <Field label={t('isicActivities.sectionCode')} value={isicActivity.section_code} />
                        <Field label={t('isicActivities.divisionCode')} value={isicActivity.division_code} />
                        <Field label={t('isicActivities.groupCode')} value={isicActivity.group_code} />
                        <Field label={t('isicActivities.classCode')} value={isicActivity.class_code} />
                        <Field label={t('isicActivities.nameEn')} value={isicActivity.name_en} />
                        <Field label={t('isicActivities.nameAm')} value={isicActivity.name_am} />
                        <Field
                            label={t('common.status')}
                            value={<StatusBadge status={isicActivity.is_active ? 'active' : 'inactive'} />}
                        />
                        <Field label={t('isicActivities.sortOrder')} value={isicActivity.sort_order} />
                    </div>
                    {(isicActivity.description_en || isicActivity.description_am) && (
                        <div className="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-slate-800">
                            {isicActivity.description_en && (
                                <div>
                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                        {t('isicActivities.descriptionEn')}
                                    </div>
                                    <div className="mt-1 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
                                        {isicActivity.description_en}
                                    </div>
                                </div>
                            )}
                            {isicActivity.description_am && (
                                <div>
                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                        {t('isicActivities.descriptionAm')}
                                    </div>
                                    <div className="mt-1 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
                                        {isicActivity.description_am}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </section>
                <aside className="space-y-4">
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs text-gray-500 dark:text-slate-400">{t('common.createdAt')}</div>
                        <div className="mt-1 text-sm text-gray-700 dark:text-slate-300">
                            <LocalizedDateDisplay value={isicActivity.created_at} withTime />
                        </div>
                        <div className="mt-3 text-xs text-gray-500 dark:text-slate-400">{t('common.updatedAt')}</div>
                        <div className="mt-1 text-sm text-gray-700 dark:text-slate-300">
                            <LocalizedDateDisplay value={isicActivity.updated_at} withTime />
                        </div>
                    </section>
                    <div>
                        <Link
                            href={route('isic-activities.index')}
                            className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400"
                        >
                            {t('isicActivities.backToList')}
                        </Link>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, value, mono }: { label: string; value: any; mono?: boolean }) {
    return (
        <div>
            <div className="text-xs text-gray-500 dark:text-slate-400">{label}</div>
            <div className={`mt-1 ${mono ? 'font-mono' : ''}`}>{value ?? '—'}</div>
        </div>
    );
}
