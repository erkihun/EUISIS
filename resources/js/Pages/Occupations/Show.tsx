import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

export default function OccupationsShow({ occupation }: { occupation: any }) {
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
        if (confirmed) router.delete(route('occupations.archive', occupation.id));
    }

    async function handleRestore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('occupations.restore', occupation.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('occupations.index')}
                    title={`${occupation.isco_code} · ${occupation.name_en ?? occupation.name_am ?? ''}`}
                    actions={
                        <div className="flex gap-3">
                            {occupation.can?.update && (
                                <Link
                                    href={route('occupations.edit', occupation.id)}
                                    className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {occupation.can?.archive && !occupation.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                                >
                                    {t('occupations.archiveOccupation')}
                                </button>
                            )}
                            {occupation.can?.restore && occupation.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                                >
                                    {t('occupations.restoreOccupation')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={occupation.name_en ?? occupation.isco_code} />
            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2 text-sm">
                        <Field label={t('occupations.iscoCode')} value={occupation.isco_code} mono />
                        <Field
                            label={t('common.status')}
                            value={<StatusBadge status={occupation.is_active ? 'active' : 'inactive'} />}
                        />
                        <Field label={t('occupations.nameEn')} value={occupation.name_en} />
                        <Field label={t('occupations.nameAm')} value={occupation.name_am} />
                        <Field label={t('occupations.skillSpecialization')} value={occupation.skill_specialization} />
                    </div>
                    {(occupation.description_en || occupation.description_am) && (
                        <div className="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-slate-800">
                            {occupation.description_en && (
                                <div>
                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                        {t('occupations.descriptionEn')}
                                    </div>
                                    <div className="mt-1 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
                                        {occupation.description_en}
                                    </div>
                                </div>
                            )}
                            {occupation.description_am && (
                                <div>
                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                        {t('occupations.descriptionAm')}
                                    </div>
                                    <div className="mt-1 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
                                        {occupation.description_am}
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
                            <LocalizedDateDisplay value={occupation.created_at} withTime />
                        </div>
                        <div className="mt-3 text-xs text-gray-500 dark:text-slate-400">{t('common.updatedAt')}</div>
                        <div className="mt-1 text-sm text-gray-700 dark:text-slate-300">
                            <LocalizedDateDisplay value={occupation.updated_at} withTime />
                        </div>
                    </section>
                    <div>
                        <Link
                            href={route('occupations.index')}
                            className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400"
                        >
                            {t('occupations.backToList')}
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
