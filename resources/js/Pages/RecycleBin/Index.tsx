import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { TrashIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { toast } from '@/lib/toast';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { buttonClassName, cx, inputClassName as uiInputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

    const inputClassName = uiInputClassName;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
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
        router.delete(route('recycle-bin.force-delete', { type: record.type, id: record.id }), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('recycleBin.deletedSuccessfully')),
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
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <form className="grid gap-3 lg:grid-cols-5" onSubmit={submit}>
                        <input
                            className={inputClassName}
                            value={form.data.search}
                            placeholder={t('recycleBin.searchPlaceholder')}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                        <select className={inputClassName} value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                            <option value="">{t('recycleBin.filterByType')}</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {t(type.label_key)}
                                </option>
                            ))}
                        </select>
                        <LocalizedDatePicker className={inputClassName} value={form.data.deleted_from} onChange={(iso) => form.setData('deleted_from', iso)} aria-label={t('recycleBin.deletedFrom')} />
                        <LocalizedDatePicker className={inputClassName} value={form.data.deleted_to} onChange={(iso) => form.setData('deleted_to', iso)} aria-label={t('recycleBin.deletedTo')} />
                        <button type="submit" className={buttonClassName({ variant: 'primary' })}>
                            {t('common.filter')}
                        </button>
                    </form>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {records.data.length === 0 ? (
                        <div className="p-8">
                            <EmptyState title={t('recycleBin.noDeletedRecords')} />
                        </div>
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
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-200">
                                                    <TrashIcon className="h-3.5 w-3.5" />
                                                    {t(record.type_label_key)}
                                                </span>
                                            </td>
                                            <td className={cx(tableCellClassName, 'font-medium text-[color:var(--app-foreground)]')}>{record.display_name}</td>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>{record.code ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{record.deleted_by?.name ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                                {record.deleted_at ? <LocalizedDateDisplay value={record.deleted_at} withTime /> : '-'}
                                            </td>
                                            <td className={cx(tableCellClassName, 'max-w-xs truncate text-[color:var(--app-muted-foreground)]')}>{record.deletion_reason ?? '-'}</td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <div className="flex items-center justify-end gap-2">
                                                    {record.can.restore ? (
                                                        <button type="button" onClick={() => restore(record)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                            {t('recycleBin.restoreRecord')}
                                                        </button>
                                                    ) : (
                                                        <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('recycleBin.noRestorePermission')}</span>
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

                    <div className="border-t border-[color:var(--app-border)] px-4 py-3 text-xs text-[color:var(--app-muted-foreground)]">
                        {records.meta.total} {t('common.results')}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
