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
type OccupationRow = {
    id: string;
    isco_code: string;
    name_en: string | null;
    name_am: string | null;
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

export default function OccupationsIndex({
    occupations,
    meta,
    filters,
    can,
}: {
    occupations: OccupationRow[];
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
        router.get(route('occupations.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('occupations.archive', id));
    }

    async function handleRestore(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('occupations.restore', id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('occupations.occupations')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('occupations.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                {t('occupations.createOccupation')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('occupations.occupations')} />

            <div className="space-y-6">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <form className="grid gap-3 sm:grid-cols-2 md:grid-cols-3" onSubmit={submit}>
                        <input
                            className={inputCls}
                            value={form.data.search}
                            placeholder={t('occupations.searchOccupations')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                        <select
                            className={inputCls}
                            value={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.value)}
                        >
                            <option value="">{t('occupations.allStatuses')}</option>
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
                    {occupations.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('occupations.noOccupationsFound')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('occupations.iscoCode'),
                                            t('occupations.nameEn'),
                                            t('common.status'),
                                            '',
                                        ].map((heading, i) => (
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
                                    {occupations.map((occ) => (
                                        <tr
                                            key={occ.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={cx(tableCellClassName, 'font-mono text-xs')}>
                                                <Link
                                                    href={route('occupations.show', occ.id)}
                                                    className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                >
                                                    {occ.isco_code}
                                                </Link>
                                            </td>
                                            <td className={cx(tableCellClassName, 'font-medium')}>
                                                {occ.name_en ?? occ.name_am ?? '—'}
                                            </td>
                                            <td className={tableCellClassName}>
                                                <StatusBadge status={occ.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end gap-3">
                                                    <Link
                                                        href={route('occupations.show', occ.id)}
                                                        className="text-xs font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                                                    >
                                                        {t('common.view')}
                                                    </Link>
                                                    {occ.can.update && (
                                                        <Link
                                                            href={route('occupations.edit', occ.id)}
                                                            className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)]"
                                                        >
                                                            {t('common.edit')}
                                                        </Link>
                                                    )}
                                                    {occ.can.archive && !occ.deleted_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleArchive(occ.id)}
                                                            className="text-xs font-medium text-red-600 hover:text-red-800"
                                                        >
                                                            {t('common.delete')}
                                                        </button>
                                                    )}
                                                    {occ.can.restore && occ.deleted_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRestore(occ.id)}
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
                    <div className="flex items-center justify-between text-sm text-[color:var(--app-muted-foreground)]">
                        <span>
                            {t('common.page')} {meta.current_page} {t('common.of')} {meta.last_page} ({meta.total}{' '}
                            {t('common.total').toLowerCase()})
                        </span>
                        <div className="flex gap-2">
                            {meta.current_page > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get(route('occupations.index'), {
                                            ...filters,
                                            page: meta.current_page - 1,
                                        })
                                    }
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    ‹
                                </button>
                            )}
                            {meta.current_page < meta.last_page && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get(route('occupations.index'), {
                                            ...filters,
                                            page: meta.current_page + 1,
                                        })
                                    }
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
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
