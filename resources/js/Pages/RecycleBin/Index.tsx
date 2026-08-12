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

    const inputClassName =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

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
                <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
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
                        <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            {t('common.filter')}
                        </button>
                    </form>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    {records.data.length === 0 ? (
                        <div className="p-8">
                            <EmptyState title={t('recycleBin.noDeletedRecords')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-slate-950 dark:text-slate-400">
                                    <tr>
                                        <th className="px-4 py-3">{t('recycleBin.recordType')}</th>
                                        <th className="px-4 py-3">{t('recycleBin.displayName')}</th>
                                        <th className="px-4 py-3">{t('common.code')}</th>
                                        <th className="px-4 py-3">{t('recycleBin.deletedBy')}</th>
                                        <th className="px-4 py-3">{t('recycleBin.deletedAt')}</th>
                                        <th className="px-4 py-3">{t('recycleBin.deletionReason')}</th>
                                        <th className="px-4 py-3 text-right">{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {records.data.map((record) => (
                                        <tr key={`${record.type}-${record.id}`} className="text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/60">
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-200">
                                                    <TrashIcon className="h-3.5 w-3.5" />
                                                    {t(record.type_label_key)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{record.display_name}</td>
                                            <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-300">{record.code ?? '-'}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-300">{record.deleted_by?.name ?? '-'}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-300">
                                                {record.deleted_at ? <LocalizedDateDisplay value={record.deleted_at} withTime /> : '-'}
                                            </td>
                                            <td className="max-w-xs truncate px-4 py-3 text-gray-600 dark:text-slate-300">{record.deletion_reason ?? '-'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    {record.can.restore ? (
                                                        <button type="button" onClick={() => restore(record)} className="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900/50 dark:text-emerald-300 dark:hover:bg-emerald-950/30">
                                                            {t('recycleBin.restoreRecord')}
                                                        </button>
                                                    ) : (
                                                        <span className="text-xs text-gray-400 dark:text-slate-500">{t('recycleBin.noRestorePermission')}</span>
                                                    )}
                                                    {record.can.forceDelete && (
                                                        <button type="button" onClick={() => permanentlyDelete(record)} className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30">
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

                    <div className="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-slate-800 dark:text-slate-400">
                        {records.meta.total} {t('common.results')}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
