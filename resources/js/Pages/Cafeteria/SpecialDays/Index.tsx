import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type SpecialDay = {
    id: string; special_date: string; name_en: string; name_am: string | null;
    day_type: string; day_type_label: string; is_open: boolean; is_subsidy_day: boolean;
    reason_en: string | null; deleted_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};
type Meta = { current_page: number; last_page: number; total: number };

export default function SpecialDaysIndex({ days, meta, filters, can }: { days: SpecialDay[]; meta: Meta; filters: Record<string, string>; can: { create: boolean } }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const hasActiveFilters = Object.entries(filters).some(([key, value]) => key !== 'page' && Boolean(value));

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.special-days.archive', id));
    }

    function handleRestore(id: string) {
        router.post(route('cafeteria.special-days.restore', id));
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        const fd = new FormData(e.currentTarget);
        router.get(route('cafeteria.special-days.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.specialDays')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.special-days.create')} className={buttonClassName({ variant: 'primary' })}>
                            + {t('cafeteria.addSpecialDay')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.specialDays')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('cafeteria.special-days.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} />
                    </FilterField>
                    <FilterField label={t('cafeteria.year')}>
                        <Input name="year" type="number" defaultValue={filters.year ?? ''} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    {days.length === 0 ? <EmptyState description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} /> : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.specialDate')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.nameEn')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.specialDayType')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.isOpen')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.isSubsidyDay')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}></th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {days.map(d => (
                                        <tr key={d.id} className={`hover:bg-[color:var(--app-surface-muted)] ${d.deleted_at ? 'opacity-60' : ''}`}>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}><LocalizedDateDisplay value={d.special_date} /></td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{d.name_en}</td>
                                            <td className={tableCellClassName}>
                                                <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                                    {d.day_type_label}
                                                </span>
                                            </td>
                                            <td className={tableCellClassName}>
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${d.is_open ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'}`}>
                                                    {d.is_open ? t('cafeteria.openDay') : t('cafeteria.closedDay')}
                                                </span>
                                            </td>
                                            <td className={tableCellClassName}>
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${d.is_subsidy_day ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-[color:var(--color-primary)]' : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]'}`}>
                                                    {d.is_subsidy_day ? t('cafeteria.isSubsidyDay') : t('common.no')}
                                                </span>
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.restore'), onClick: () => handleRestore(d.id), show: !!d.deleted_at && d.can.restore },
                                                            { label: t('common.edit'), href: route('cafeteria.special-days.edit', d.id), show: !d.deleted_at && d.can.update },
                                                            { label: t('common.delete'), onClick: () => handleArchive(d.id), variant: 'danger', show: !d.deleted_at && d.can.archive },
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
                </div>
                <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('cafeteria.totalTransactions').replace('Total', 'Total')}: {meta.total}</p>
            </div>
        </AuthenticatedLayout>
    );
}
