import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type GradeLevelRow = {
    id: string;
    name: string;
    is_active: boolean;
    deleted_at: string | null;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

type Meta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

export default function GradeLevelsIndex({
    gradeLevels,
    meta,
    filters,
    can,
}: {
    gradeLevels: GradeLevelRow[];
    meta: Meta;
    filters: Record<string, string>;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        is_active: filters.is_active ?? '',
    });

    const inputCls = inputClassName;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('grade-levels.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('grade-levels.archive', id));
    }

    async function handleRestore(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('grade-levels.restore', id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('gradeLevels.gradeLevels')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('grade-levels.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                {t('gradeLevels.createGradeLevel')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('gradeLevels.gradeLevels')} />

            <div className="space-y-6">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <form className="grid gap-3 sm:grid-cols-2 md:grid-cols-3" onSubmit={submit}>
                        <input
                            className={inputCls}
                            value={form.data.search}
                            placeholder={t('gradeLevels.search')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                        <select
                            className={inputCls}
                            value={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.value)}
                        >
                            <option value="">All Statuses</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </select>
                        <button
                            className={buttonClassName({ variant: 'primary' })}
                            type="submit"
                        >
                            {t('common.filter')}
                        </button>
                    </form>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {gradeLevels.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('gradeLevels.noGradeLevels')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[t('gradeLevels.name'), t('common.status'), ''].map((heading, i) => (
                                            <th
                                                key={i}
                                                className={cx(tableHeaderCellClassName, 'text-left')}
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {gradeLevels.map((gl) => (
                                        <tr
                                            key={gl.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={cx(tableCellClassName, 'font-medium')}>
                                                <Link
                                                    href={route('grade-levels.show', gl.id)}
                                                    className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                >
                                                    {gl.name}
                                                </Link>
                                            </td>
                                            <td className={tableCellClassName}>
                                                <StatusBadge status={gl.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end gap-3">
                                                    {gl.can.view && (
                                                        <Link
                                                            href={route('grade-levels.show', gl.id)}
                                                            className="text-xs font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                                                        >
                                                            {t('common.view')}
                                                        </Link>
                                                    )}
                                                    {gl.can.update && (
                                                        <Link
                                                            href={route('grade-levels.edit', gl.id)}
                                                            className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                        >
                                                            {t('common.edit')}
                                                        </Link>
                                                    )}
                                                    {gl.can.archive && (
                                                        <button
                                                            className="text-xs font-medium text-red-600 hover:text-red-800"
                                                            onClick={() => handleArchive(gl.id)}
                                                        >
                                                            {t('gradeLevels.archive')}
                                                        </button>
                                                    )}
                                                    {gl.can.restore && (
                                                        <button
                                                            className="text-xs font-medium text-green-600 hover:text-green-800"
                                                            onClick={() => handleRestore(gl.id)}
                                                        >
                                                            {t('gradeLevels.restore')}
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
                    <div className="flex items-center justify-between text-sm text-[color:var(--app-muted-foreground)]">
                        <span>
                            Page {meta.current_page} / {meta.last_page} — {meta.total} {t('common.total')}
                        </span>
                        <div className="flex gap-2">
                            {meta.current_page > 1 && (
                                <button
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                    onClick={() =>
                                        router.get(route('grade-levels.index'), { ...form.data, page: meta.current_page - 1 })
                                    }
                                >
                                    {t('common.previous')}
                                </button>
                            )}
                            {meta.current_page < meta.last_page && (
                                <button
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                    onClick={() =>
                                        router.get(route('grade-levels.index'), { ...form.data, page: meta.current_page + 1 })
                                    }
                                >
                                    {t('common.next')}
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
