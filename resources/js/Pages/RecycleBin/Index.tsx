import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, router, useForm } from '@inertiajs/react';
import { TrashIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { toast } from '@/lib/toast';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx, inputClassName as uiInputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type RecycleBinRecord = {
    type: string;
    type_label_key: string;
    id: string;
    display_name: string;
    code: string | null;
    deleted_at: string | null;
    deleted_by: { id: number; name: string } | null;
    deletion_reason: string | null;
    can: { restore: boolean; forceDelete: boolean; view_details: boolean };
};

type Props = {
    records: {
        data: RecycleBinRecord[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    filters: {
        type?: string;
        search?: string;
        deleted_from?: string;
        deleted_to?: string;
    };
    types: Array<{ value: string; label_key: string }>;
    can: { restore: boolean; forceDelete: boolean };
};

export default function RecycleBinIndex({ records, filters, types }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({
        type: filters.type ?? '',
        search: filters.search ?? '',
        deleted_from: filters.deleted_from ?? '',
        deleted_to: filters.deleted_to ?? '',
    });

    const dateCls = cx(uiInputClassName, 'w-full');
    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('recycle-bin.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    async function restore(record: RecycleBinRecord) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('recycleBin.confirmRestoreMessage'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (!confirmed) return;
        router.post(route('recycle-bin.restore', { type: record.type, id: record.id }), {}, { preserveScroll: true });
    }

    async function permanentlyDelete(record: RecycleBinRecord) {
        const { confirmed } = await confirm({
            title: t('recycleBin.confirmPermanentDeleteTitle'),
            description: t('recycleBin.confirmPermanentDeleteMessage'),
            confirmLabel: t('recycleBin.permanentlyDelete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        // The server flashes the success itself; a second toast here showed the
        // same outcome twice.
        router.delete(route('recycle-bin.force-delete', { type: record.type, id: record.id }), {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.record ?? t('recycleBin.failedToDelete')),
        });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('recycleBin.title')}
                    description={t('recycleBin.description')}
                />
            )}
        >
            <Head title={t('recycleBin.title')} />

            <div className="space-y-6">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('recycle-bin.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={form.data.search} placeholder={t('recycleBin.searchPlaceholder')} onChange={(event) => form.setData('search', event.target.value)} />
                    </FilterField>
                    <FilterField label={t('recycleBin.filterByType')}>
                        <Select value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {t(type.label_key)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('recycleBin.deletedFrom')} htmlFor="recycle-deleted-from">
                        <LocalizedDatePicker id="recycle-deleted-from" className={dateCls} value={form.data.deleted_from} onChange={(iso) => form.setData('deleted_from', iso)} />
                    </FilterField>
                    <FilterField label={t('recycleBin.deletedTo')} htmlFor="recycle-deleted-to">
                        <LocalizedDatePicker id="recycle-deleted-to" className={dateCls} value={form.data.deleted_to} onChange={(iso) => form.setData('deleted_to', iso)} />
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {records.data.length === 0 ? (
                        <EmptyState title={t('recycleBin.noDeletedRecords')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('recycleBin.recordType')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('recycleBin.displayName')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('common.code')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('recycleBin.deletedBy')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('recycleBin.deletedAt')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('recycleBin.deletionReason')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {records.data.map((record) => (
                                        <tr key={`${record.type}-${record.id}`} className={tableRowClassName}>
                                            <td className={tableCellClassName}>
                                                <span className={badgeClassName('danger')}>
                                                    <TrashIcon className="h-3.5 w-3.5" />
                                                    {t(record.type_label_key)}
                                                </span>
                                            </td>
                                            <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{record.display_name}</td>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-muted-foreground)')}>{record.code ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{record.deleted_by?.name ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                                {record.deleted_at ? <LocalizedDateDisplay value={record.deleted_at} withTime /> : '-'}
                                            </td>
                                            <td className={cx(tableCellClassName, 'max-w-xs truncate text-(--app-muted-foreground)')}>{record.deletion_reason ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <div className="flex items-center justify-end gap-2">
                                                    {record.can.restore ? (
                                                        <button type="button" onClick={() => restore(record)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                            {t('recycleBin.restoreRecord')}
                                                        </button>
                                                    ) : (
                                                        <span className="text-xs text-(--app-muted-foreground)">{t('recycleBin.noRestorePermission')}</span>
                                                    )}
                                                    {record.can.forceDelete && (
                                                        <button type="button" onClick={() => permanentlyDelete(record)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                            {t('recycleBin.permanentlyDelete')}
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
                    <ListPagination meta={records.meta} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
