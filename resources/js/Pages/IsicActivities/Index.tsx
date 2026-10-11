import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import IsicActivityLevelBadge from '@/Components/isic-activities/IsicActivityLevelBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, Select, actionLinkClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
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
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
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
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('isic-activities.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search"
                            value={form.data.search}
                            placeholder={t('isicActivities.searchIsicActivities')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('isicActivities.activityLevel')}>
                        <Select
                            value={form.data.level}
                            onChange={(e) => form.setData('level', e.target.value)}
                        >
                            <option value="">{t('isicActivities.allLevels')}</option>
                            {levels.map((l) => (
                                <option key={l} value={l}>
                                    {l}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select
                            value={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.value)}
                        >
                            <option value="">{t('isicActivities.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {isicActivities.length === 0 ? (
                        <EmptyState title={t('isicActivities.noIsicActivitiesFound')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
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
                                                className={cx(tableHeaderCellClassName, 'text-left')}
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {isicActivities.map((act) => (
                                        <tr
                                            key={act.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={cx(tableCellClassName, 'font-mono text-xs')}>
                                                <Link
                                                    href={route('isic-activities.show', act.id)}
                                                    className="text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]"
                                                >
                                                    {act.isic_code}
                                                </Link>
                                            </td>
                                            <td className={cx(tableCellClassName, 'font-medium')}>
                                                {act.name_en ?? act.name_am ?? '—'}
                                            </td>
                                            <td className={tableCellClassName}>
                                                <IsicActivityLevelBadge level={act.level} />
                                            </td>
                                            <td className={tableCellClassName}>{act.section_code ?? '—'}</td>
                                            <td className={tableCellClassName}>
                                                <StatusBadge status={act.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end gap-3">
                                                    <Link
                                                        href={route('isic-activities.show', act.id)}
                                                        className={actionLinkClassName({ tone: 'neutral' })}
                                                    >
                                                        {t('common.view')}
                                                    </Link>
                                                    {act.can.update && (
                                                        <Link
                                                            href={route('isic-activities.edit', act.id)}
                                                            className={actionLinkClassName()}
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
                    <ListPagination meta={meta} />
                </section>

            </div>
        </AuthenticatedLayout>
    );
}
