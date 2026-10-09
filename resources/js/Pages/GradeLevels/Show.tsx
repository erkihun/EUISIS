import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

export default function GradeLevelsShow({ gradeLevel }: { gradeLevel: any }) {
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
        if (confirmed) router.delete(route('grade-levels.archive', gradeLevel.id));
    }

    async function handleRestore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('grade-levels.restore', gradeLevel.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('grade-levels.index')}
                    title={gradeLevel.name}
                    actions={
                        <div className="flex gap-3">
                            {gradeLevel.can?.update && (
                                <Link
                                    href={route('grade-levels.edit', gradeLevel.id)}
                                    className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {gradeLevel.can?.archive && !gradeLevel.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                                >
                                    {t('gradeLevels.archive')}
                                </button>
                            )}
                            {gradeLevel.can?.restore && gradeLevel.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    {t('gradeLevels.restore')}
                                </button>
                            )}
                            <Link
                                href={route('grade-levels.index')}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200"
                            >
                                {t('common.back')}
                            </Link>
                        </div>
                    }
                />
            }
        >
            <Head title={gradeLevel.name} />

            <div className="rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <dl className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t('gradeLevels.name')}
                        </dt>
                        <dd className="mt-1 text-sm font-medium text-gray-900 dark:text-slate-100">{gradeLevel.name}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t('common.status')}
                        </dt>
                        <dd className="mt-1">
                            <StatusBadge status={gradeLevel.is_active ? 'active' : 'inactive'} />
                        </dd>
                    </div>
                </dl>
            </div>
        </AuthenticatedLayout>
    );
}
