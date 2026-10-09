import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

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
    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.special-days.archive', id));
    }

    function handleRestore(id: string) {
        router.post(route('cafeteria.special-days.restore', id));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('cafeteria.special-days.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.specialDays')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.special-days.create')} className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            + {t('cafeteria.addSpecialDay')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.specialDays')} />
            <div className="space-y-4">
                <form className="flex gap-3" onSubmit={submit}>
                    <input name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} className={inputCls} />
                    <input name="year" type="number" defaultValue={filters.year ?? ''} placeholder="Year" className={`${inputCls} w-24`} />
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {days.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.specialDate')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.nameEn')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.specialDayType')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.isOpen')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.isSubsidyDay')}</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50 dark:divide-slate-800">
                                    {days.map(d => (
                                        <tr key={d.id} className={`hover:bg-gray-50 dark:hover:bg-slate-800/40 ${d.deleted_at ? 'opacity-60' : ''}`}>
                                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300"><LocalizedDateDisplay value={d.special_date} /></td>
                                            <td className="px-4 py-3 text-gray-800 dark:text-slate-200">{d.name_en}</td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                                    {d.day_type_label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${d.is_open ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'}`}>
                                                    {d.is_open ? t('cafeteria.openDay') : t('cafeteria.closedDay')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${d.is_subsidy_day ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-[color:var(--color-primary)]' : 'bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-400'}`}>
                                                    {d.is_subsidy_day ? t('cafeteria.isSubsidyDay') : t('common.no')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {d.deleted_at
                                                        ? d.can.restore && <button onClick={() => handleRestore(d.id)} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{t('common.restore')}</button>
                                                        : <>
                                                            {d.can.update && <Link href={route('cafeteria.special-days.edit', d.id)} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{t('common.edit')}</Link>}
                                                            {d.can.archive && <button onClick={() => handleArchive(d.id)} className="text-sm text-red-500 hover:underline">{t('common.delete')}</button>}
                                                        </>
                                                    }
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
                <p className="text-xs text-gray-400 dark:text-slate-500">{t('cafeteria.totalTransactions').replace('Total', 'Total')}: {meta.total}</p>
            </div>
        </AuthenticatedLayout>
    );
}
