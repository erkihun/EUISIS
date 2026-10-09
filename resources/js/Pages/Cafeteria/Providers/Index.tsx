import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Provider = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    is_active: boolean;
    phone_number: string | null;
    location: string | null;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};
type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function ProvidersIndex({
    providers,
    meta,
    filters,
    can,
}: {
    providers: Provider[];
    meta: Meta;
    filters: Record<string, string>;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: t('confirmations.thisRecordWillMoveToRecycleBin'), confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.providers.archive', id));
    }

    async function handleRestore(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmRestoreTitle'), description: '', confirmLabel: t('confirmations.restore'), cancelLabel: t('confirmations.cancel'), variant: 'default' });
        if (confirmed) router.post(route('cafeteria.providers.restore', id));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('cafeteria.providers.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.providers')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.providers.create')} className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            + {t('cafeteria.addProvider')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <div className="space-y-4">
                <form className="flex flex-wrap gap-3" onSubmit={submit}>
                    <input name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} className={inputCls} />
                    <select name="is_active" defaultValue={filters.is_active ?? ''} className={inputCls}>
                        <option value="">{t('common.active')}</option>
                        <option value="0">{t('common.archived')}</option>
                    </select>
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {providers.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.providerCode')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.nameEn')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.phoneNumber')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.isActive')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {providers.map((p) => (
                                        <tr key={p.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">{p.code}</td>
                                            <td className="px-4 py-3 font-medium">
                                                <Link href={route('cafeteria.providers.show', p.id)} className="text-gray-900 hover:text-[color:var(--color-primary)] hover:underline dark:text-slate-100 dark:hover:text-blue-400">
                                                    {p.name_en}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-gray-500">{p.phone_number ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={p.is_active ? 'active' : 'inactive'} label={p.is_active ? t('common.active') : t('common.inactive')} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link href={route('cafeteria.providers.show', p.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{t('common.view')}</Link>
                                                    {p.can.update && <Link href={route('cafeteria.providers.edit', p.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">{t('common.edit')}</Link>}
                                                    {p.can.archive && !p.deleted_at && <button onClick={() => handleArchive(p.id)} className="text-xs text-red-600 hover:underline">{t('common.archive')}</button>}
                                                    {p.can.restore && p.deleted_at && <button onClick={() => handleRestore(p.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">{t('common.restore')}</button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
