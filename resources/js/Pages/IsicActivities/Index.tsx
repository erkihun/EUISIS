import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import IsicActivityLevelBadge from '@/Components/isic-activities/IsicActivityLevelBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Row = {
    id: string;
    isic_code: string;
    name_en: string | null;
    name_am: string | null;
    level: string;
    section_code: string | null;
    is_active: boolean;
    deleted_at: string | null;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function IsicActivitiesIndex({
    isicActivities,
    meta,
    filters,
    levels,
    can,
}: {
    isicActivities: Row[];
    meta: Meta;
    filters: Record<string, string>;
    levels: string[];
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        level: filters.level ?? '',
        is_active: filters.is_active ?? '',
    });

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('isic-activities.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('isic-activities.archive', id));
    }

    async function handleRestore(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('isic-activities.restore', id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('isicActivities.isicActivities')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('isic-activities.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                {t('isicActivities.createIsicActivity')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('isicActivities.isicActivities')} />

            <div className="space-y-6">
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <form className="grid gap-3 sm:grid-cols-2 md:grid-cols-4" onSubmit={submit}>
                        <input
                            className={inputCls}
                            value={form.data.search}
                            placeholder={t('isicActivities.searchIsicActivities')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                        <select
                            className={inputCls}
                            value={form.data.level}
                            onChange={(e) => form.setData('level', e.target.value)}
                        >
                            <option value="">{t('isicActivities.allLevels')}</option>
                            {levels.map((l) => (
                                <option key={l} value={l}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <select
                            className={inputCls}
                            value={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.value)}
                        >
                            <option value="">{t('isicActivities.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </select>
                        <button
                            className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            type="submit"
                        >
                            {t('common.filter')}
                        </button>
                    </form>
                </section>

                <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {isicActivities.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('isicActivities.noIsicActivitiesFound')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        {[
                                            t('isicActivities.isicCode'),
                                            t('isicActivities.nameEn'),
                                            t('isicActivities.activityLevel'),
                                            t('isicActivities.section'),
                                            t('common.status'),
                                            '',
                                        ].map((heading, i) => (
                                            <th
                                                key={i}
                                                className="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {isicActivities.map((act) => (
                                        <tr
                                            key={act.id}
                                            className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                        >
                                            <td className="px-4 py-3 font-mono text-xs">
                                                <Link
                                                    href={route('isic-activities.show', act.id)}
                                                    className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                >
                                                    {act.isic_code}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 font-medium">
                                                {act.name_en ?? act.name_am ?? '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <IsicActivityLevelBadge level={act.level} />
                                            </td>
                                            <td className="px-4 py-3">{act.section_code ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={act.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-3">
                                                    <Link
                                                        href={route('isic-activities.show', act.id)}
                                                        className="text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-slate-400"
                                                    >
                                                        {t('common.view')}
                                                    </Link>
                                                    {act.can.update && (
                                                        <Link
                                                            href={route('isic-activities.edit', act.id)}
                                                            className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)]"
                                                        >
                                                            {t('common.edit')}
                                                        </Link>
                                                    )}
                                                    {act.can.archive && !act.deleted_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleArchive(act.id)}
                                                            className="text-xs font-medium text-red-600 hover:text-red-800"
                                                        >
                                                            {t('common.delete')}
                                                        </button>
                                                    )}
                                                    {act.can.restore && act.deleted_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRestore(act.id)}
                                                            className="text-xs font-medium text-emerald-600 hover:text-emerald-800"
                                                        >
                                                            {t('common.restore')}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {meta.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm text-gray-500 dark:text-slate-400">
                        <span>
                            {t('common.page')} {meta.current_page} {t('common.of')} {meta.last_page} ({meta.total}{' '}
                            {t('common.total').toLowerCase()})
                        </span>
                        <div className="flex gap-2">
                            {meta.current_page > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get(route('isic-activities.index'), {
                                            ...filters,
                                            page: meta.current_page - 1,
                                        })
                                    }
                                    className="rounded-lg border border-gray-300 px-3 py-1 hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                >
                                    ‹
                                </button>
                            )}
                            {meta.current_page < meta.last_page && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get(route('isic-activities.index'), {
                                            ...filters,
                                            page: meta.current_page + 1,
                                        })
                                    }
                                    className="rounded-lg border border-gray-300 px-3 py-1 hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                >
                                    ›
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
