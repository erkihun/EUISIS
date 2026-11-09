import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ClassificationName from '@/Components/ClassificationName';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type OccupationRow = {
    id: string;
    isco_code: string;
    name_en: string | null;
    name_am: string | null;
    skill_specialization: string | null;
    skill_specialization_am: string | null;
    skill_level: string | null;
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

type MajorGroup = { code: string; name_en: string | null; name_am: string | null };

export default function OccupationsIndex({
    occupations,
    meta,
    filters,
    majorGroups,
    can,
}: {
    occupations: OccupationRow[];
    meta: Meta;
    filters: Record<string, string>;
    majorGroups: MajorGroup[];
    can: { create: boolean };
}) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        search: filters.search ?? '',
        major_group: filters.major_group ?? '',
        skill_level: filters.skill_level ?? '',
        is_active: filters.is_active ?? '',
    });

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
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

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('occupations.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search"
                            value={form.data.search}
                            placeholder={t('occupations.searchOccupations')}
                            onChange={(e) => form.setData('search', e.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('occupations.majorGroup')} className="sm:w-72">
                        <Select
                            value={form.data.major_group}
                            onChange={(e) => form.setData('major_group', e.target.value)}
                        >
                            <option value="">{t('occupations.allMajorGroups')}</option>
                            {majorGroups.map((group) => (
                                <option key={group.code} value={group.code}>
                                    {group.code} · {(locale === 'am' ? group.name_am : group.name_en) ?? group.name_en ?? group.name_am}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('occupations.skillLevel')} className="sm:w-64">
                        <Select
                            value={form.data.skill_level}
                            onChange={(e) => form.setData('skill_level', e.target.value)}
                        >
                            <option value="">{t('occupations.allSkillLevels')}</option>
                            {['1', '2', '3', '4'].map((level) => (
                                <option key={level} value={level}>{t(`occupations.skillLevel${level}`)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')} className="sm:w-40">
                        <Select
                            value={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.value)}
                        >
                            <option value="">{t('occupations.allStatuses')}</option>
                            <option value="1">{t('common.active')}</option>
                            <option value="0">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {occupations.length === 0 ? (
                        <EmptyState title={t('occupations.noOccupationsFound')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'w-24 text-left')}>{t('occupations.iscoCode')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('occupations.occupation')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden w-1/3 text-left xl:table-cell')}>{t('occupations.skillSpecialization')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden w-24 text-left sm:table-cell')}>{t('occupations.skillLevel')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden w-24 text-left xl:table-cell')}>{t('common.status')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'w-12')}><span className="sr-only">{t('common.actions')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {occupations.map((occ) => (
                                        <tr
                                            key={occ.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap font-mono text-xs align-top')}>
                                                <Link
                                                    href={route('occupations.show', occ.id)}
                                                    className="font-semibold text-primary hover:text-primary-hover dark:text-primary"
                                                >
                                                    {occ.isco_code}
                                                </Link>
                                            </td>
                                            <td className={cx(tableCellClassName, 'whitespace-normal align-top')}>
                                                <ClassificationName nameEn={occ.name_en} nameAm={occ.name_am} />
                                                {occ.skill_level && (
                                                    <div className="mt-1 text-xs text-(--app-muted-foreground) sm:hidden">
                                                        {t('occupations.skillLevelNumber')} {occ.skill_level}
                                                    </div>
                                                )}
                                                {(occ.skill_specialization || occ.skill_specialization_am) && (
                                                    <div className="mt-2 text-sm xl:hidden">
                                                        <div className="mb-0.5 text-xs text-(--app-muted-foreground)">{t('occupations.skillSpecialization')}</div>
                                                        <ClassificationName nameEn={occ.skill_specialization} nameAm={occ.skill_specialization_am} plain />
                                                    </div>
                                                )}
                                            </td>
                                            <td className={cx(tableCellClassName, 'hidden whitespace-normal align-top xl:table-cell')}>
                                                <ClassificationName nameEn={occ.skill_specialization} nameAm={occ.skill_specialization_am} plain />
                                            </td>
                                            <td className={cx(tableCellClassName, 'hidden align-top sm:table-cell')}>
                                                {occ.skill_level ? (
                                                    <span
                                                        title={t(`occupations.skillLevel${occ.skill_level}`)}
                                                        className="inline-flex items-center whitespace-nowrap rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs font-medium text-(--app-foreground)"
                                                    >
                                                        {t('occupations.skillLevelNumber')} {occ.skill_level}
                                                    </span>
                                                ) : '—'}
                                            </td>
                                            <td className={cx(tableCellClassName, 'hidden align-top xl:table-cell')}>
                                                <StatusBadge status={occ.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td className={cx(tableCellClassName, 'align-top')}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.view'), href: route('occupations.show', occ.id) },
                                                            { label: t('common.edit'), href: route('occupations.edit', occ.id), show: occ.can.update },
                                                            { label: t('common.delete'), onClick: () => handleArchive(occ.id), variant: 'danger', show: occ.can.archive && !occ.deleted_at },
                                                            { label: t('common.restore'), onClick: () => handleRestore(occ.id), show: !!(occ.can.restore && occ.deleted_at) },
                                                        ]}
                                                    />
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
