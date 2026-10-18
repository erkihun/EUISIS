import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, buttonClassName, cx, hasFilterQuery, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type Holiday = {
    id: string; name_en: string; name_am: string | null;
    holiday_date: string; is_recurring: boolean; is_active: boolean;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean };
};
type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function HolidaysIndex({ holidays, meta, filters, year, can }: { holidays: Holiday[]; meta: Meta; filters: Record<string, string>; year: number; can: { create: boolean } }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const hasActiveFilters = hasFilterQuery();

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.holidays.archive', id));
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        const fd = new FormData(e.currentTarget);
        router.get(route('cafeteria.holidays.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.holidays')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.holidays.create')} className={buttonClassName({ variant: 'primary' })}>
                            + {t('cafeteria.addHoliday')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.holidays')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('cafeteria.holidays.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('cafeteria.year')}>
                        <Input type="number" name="year" defaultValue={String(year)} min="2000" max="2100" />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    {holidays.length === 0 ? <EmptyState description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} /> : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.holidayDate')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.nameEn')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.nameAm')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-center')}>{t('cafeteria.isRecurring')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {holidays.map((h) => (
                                        <tr key={h.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}><LocalizedDateDisplay value={h.holiday_date} /></td>
                                            <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{h.name_en}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{h.name_am ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-center')}>{h.is_recurring ? '✓' : '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('cafeteria.holidays.edit', h.id), show: h.can.update },
                                                            { label: t('common.archive'), onClick: () => handleArchive(h.id), variant: 'danger', show: h.can.archive && !h.deleted_at },
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
            </div>
        </AuthenticatedLayout>
    );
}
