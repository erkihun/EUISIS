import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

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
    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.holidays.archive', id));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('cafeteria.holidays.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.holidays')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.holidays.create')} className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            + {t('cafeteria.addHoliday')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.holidays')} />
            <div className="space-y-4">
                <form className="flex gap-3" onSubmit={submit}>
                    <input type="number" name="year" defaultValue={String(year)} min="2000" max="2100" className={inputCls} style={{ width: 90 }} />
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {holidays.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.holidayDate')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.nameEn')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.nameAm')}</th>
                                        <th className="px-4 py-3 text-center font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.isRecurring')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {holidays.map((h) => (
                                        <tr key={h.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100"><LocalizedDateDisplay value={h.holiday_date} /></td>
                                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{h.name_en}</td>
                                            <td className="px-4 py-3 text-gray-500">{h.name_am ?? '—'}</td>
                                            <td className="px-4 py-3 text-center">{h.is_recurring ? '✓' : '—'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {h.can.update && <Link href={route('cafeteria.holidays.edit', h.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">{t('common.edit')}</Link>}
                                                    {h.can.archive && !h.deleted_at && <button onClick={() => handleArchive(h.id)} className="text-xs text-red-600 hover:underline">{t('common.archive')}</button>}
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
