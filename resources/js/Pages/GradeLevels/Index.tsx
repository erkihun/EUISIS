import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, Select, actionLinkClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
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
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('grade-levels.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={form.data.search} placeholder={t('gradeLevels.search')} onChange={(e) => form.setData('search', e.target.value)} />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {gradeLevels.length === 0 ? (
                        <EmptyState title={t('gradeLevels.noGradeLevels')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
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
                                                            className={actionLinkClassName({ tone: 'neutral' })}
                                                        >
                                                            {t('common.view')}
                                                        </Link>
                                                    )}
                                                    {gl.can.update && (
                                                        <Link
                                                            href={route('grade-levels.edit', gl.id)}
                                                            className={actionLinkClassName()}
                                                        >
                                                            {t('common.edit')}
                                                        </Link>
                                                    )}
                                                    {gl.can.archive && (
                                                        <button
                                                            className={actionLinkClassName({ tone: 'danger' })}
                                                            onClick={() => handleArchive(gl.id)}
                                                        >
                                                            {t('gradeLevels.archive')}
                                                        </button>
                                                    )}
                                                    {gl.can.restore && (
                                                        <button
                                                            className={actionLinkClassName({ tone: 'success' })}
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
                    <ListPagination meta={meta} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
