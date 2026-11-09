import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ClassificationName from '@/Components/ClassificationName';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import IsicActivityLevelBadge from '@/Components/isic-activities/IsicActivityLevelBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import type { CSSProperties } from 'react';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
type SectionOption = { code: string; name_en: string | null; name_am: string | null };

const DEPTH: Record<string, number> = { section: 0, division: 1, group: 2, class: 3 };

export default function IsicActivitiesIndex({
    isicActivities,
    meta,
    filters,
    levels,
    levelCounts,
    sections,
    can,
}: {
    isicActivities: Row[];
    meta: Meta;
    filters: Record<string, string>;
    levels: string[];
    levelCounts: Record<string, number>;
    sections: SectionOption[];
    can: { create: boolean };
}) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        section: filters.section ?? '',
        level: filters.level ?? '',
        is_active: filters.is_active ?? '',
    });

    const hasActiveFilters = Object.values(filters).some(Boolean);
    // Indenting shows the hierarchy only while every level is listed in order.
    const showsHierarchy = !filters.search && !filters.level;
    const allCount = levels.reduce((sum, level) => sum + (levelCounts[level] ?? 0), 0);

    function submit() {
        router.get(route('isic-activities.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    function levelHref(level: string): string {
        const params = Object.fromEntries(Object.entries({ ...filters, level }).filter(([, value]) => value));

        return route('isic-activities.index', params);
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

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('isic-activities.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search"
                            value={form.data.search}
                            placeholder={t('isicActivities.searchIsicActivities')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('isicActivities.section')} className="sm:w-72">
                        <Select
                            value={form.data.section}
                            onChange={(e) => form.setData('section', e.target.value)}
                        >
                            <option value="">{t('isicActivities.allSections')}</option>
                            {sections.map((section) => (
                                <option key={section.code} value={section.code}>
                                    {section.code} · {(locale === 'am' ? section.name_am : section.name_en) ?? section.name_en ?? section.name_am}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')} className="sm:w-40">
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

                <nav aria-label={t('isicActivities.activityLevel')} className="flex flex-wrap gap-2">
                    {['', ...levels].map((level) => {
                        const current = (filters.level ?? '') === level;
                        return (
                            <Link
                                key={level || 'all'}
                                href={levelHref(level)}
                                preserveState
                                preserveScroll
                                aria-current={current ? 'page' : undefined}
                                className={cx(
                                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--app-primary)',
                                    current
                                        ? 'border-(--app-primary) bg-(--app-primary) text-white'
                                        : 'border-(--app-border) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted)',
                                )}
                            >
                                {level ? t(`isicActivities.${level}`) : t('isicActivities.allLevels')}
                                <span className={cx('text-xs tabular-nums', current ? 'text-white/80' : 'text-(--app-muted-foreground)')}>
                                    {level ? (levelCounts[level] ?? 0) : allCount}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                <section className={tableContainerClassName}>
                    {isicActivities.length === 0 ? (
                        <EmptyState title={t('isicActivities.noIsicActivitiesFound')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'w-24 text-left')}>{t('isicActivities.isicCode')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('isicActivities.economicActivity')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden w-32 text-left sm:table-cell')}>{t('isicActivities.activityLevel')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden w-28 text-left md:table-cell')}>{t('common.status')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'w-12')}><span className="sr-only">{t('common.actions')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {isicActivities.map((act) => {
                                        const isSectionHeading = showsHierarchy && act.level === 'section';
                                        const depth = showsHierarchy ? (DEPTH[act.level] ?? 0) : 0;
                                        return (
                                            <tr
                                                key={act.id}
                                                className={cx(tableRowClassName, isSectionHeading && 'bg-(--app-surface-muted)')}
                                            >
                                                <td className={cx(tableCellClassName, 'whitespace-nowrap font-mono text-xs align-top')}>
                                                    <Link
                                                        href={route('isic-activities.show', act.id)}
                                                        className="font-semibold text-primary hover:text-primary-hover dark:text-primary"
                                                    >
                                                        {act.isic_code}
                                                    </Link>
                                                </td>
                                                <td className={cx(tableCellClassName, 'whitespace-normal align-top')}>
                                                    <div
                                                        style={{ '--isic-depth': depth } as CSSProperties}
                                                        className="ps-[calc(var(--isic-depth)*0.5rem)] sm:ps-[calc(var(--isic-depth)*1.25rem)]"
                                                    >
                                                        <ClassificationName nameEn={act.name_en} nameAm={act.name_am} strong={isSectionHeading} />
                                                        <div className="mt-1 sm:hidden">
                                                            <IsicActivityLevelBadge level={act.level} />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className={cx(tableCellClassName, 'hidden align-top sm:table-cell')}>
                                                    <IsicActivityLevelBadge level={act.level} />
                                                </td>
                                                <td className={cx(tableCellClassName, 'hidden align-top md:table-cell')}>
                                                    <StatusBadge status={act.is_active ? 'active' : 'inactive'} />
                                                </td>
                                                <td className={cx(tableCellClassName, 'align-top')}>
                                                    <div className="flex justify-end">
                                                        <AppActionMenu
                                                            items={[
                                                                { label: t('common.view'), href: route('isic-activities.show', act.id) },
                                                                { label: t('common.edit'), href: route('isic-activities.edit', act.id), show: act.can.update },
                                                                { label: t('common.delete'), onClick: () => handleArchive(act.id), variant: 'danger', show: act.can.archive && !act.deleted_at },
                                                                { label: t('common.restore'), onClick: () => handleRestore(act.id), show: !!(act.can.restore && act.deleted_at) },
                                                            ]}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
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
