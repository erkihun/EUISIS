import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Exclusion = {
    id: string; employee_name: string | null; employee_number: string | null;
    exclusion_type: string; exclusion_type_label: string;
    starts_on: string; ends_on: string | null; return_to_work_on: string | null;
    is_open_ended: boolean; status: string; status_label: string;
    deleted_at: string | null;
    can: { update: boolean; end: boolean; archive: boolean; restore: boolean };
};
type Meta = { current_page: number; last_page: number; total: number };

const STATUS_COLORS: Record<string, string> = {
    active:    'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    ended:     'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-400',
    cancelled: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400',
};

export default function EmployeeExclusionsIndex({ exclusions, meta, filters, active_count, can }: {
    exclusions: Exclusion[]; meta: Meta; filters: Record<string, string>; active_count: number; can: { create: boolean }
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.employee-exclusions.archive', id));
    }

    async function handleEnd(id: string) {
        const { confirmed } = await confirm({ title: t('cafeteria.endExclusion'), description: t('cafeteria.employeeReturnedToWork'), confirmLabel: t('cafeteria.endExclusion'), cancelLabel: t('confirmations.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('cafeteria.employee-exclusions.end', id));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('cafeteria.employee-exclusions.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.employeeExclusions')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.employee-exclusions.create')} className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            + {t('cafeteria.addExclusion')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.employeeExclusions')} />
            <div className="space-y-4">
                {/* Active count chip */}
                {active_count > 0 && (
                    <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300">
                        <span className="font-semibold">{active_count}</span> {t('cafeteria.activeExclusions')}
                    </div>
                )}

                <form className="flex gap-3" onSubmit={submit}>
                    <input name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} className={inputCls} />
                    <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                        <option value="">All statuses</option>
                        <option value="active">{t('cafeteria.exclusionStatusActive')}</option>
                        <option value="ended">{t('cafeteria.exclusionStatusEnded')}</option>
                        <option value="cancelled">{t('cafeteria.exclusionStatusCancelled')}</option>
                    </select>
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {exclusions.length === 0 ? <EmptyState title={t('cafeteria.noExclusionsFound')} /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('common.employee')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.exclusionType')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.startsOn')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.endsOn')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">Status</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50 dark:divide-slate-800">
                                    {exclusions.map(ex => (
                                        <tr key={ex.id} className={`hover:bg-gray-50 dark:hover:bg-slate-800/40 ${ex.deleted_at ? 'opacity-60' : ''}`}>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-gray-800 dark:text-slate-200">{ex.employee_name ?? '—'}</div>
                                                {ex.employee_number && <div className="text-xs text-gray-400 dark:text-slate-500">{ex.employee_number}</div>}
                                            </td>
                                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{ex.exclusion_type_label}</td>
                                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300"><LocalizedDateDisplay value={ex.starts_on} /></td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                                {ex.is_open_ended ? <span className="italic">Open-ended</span> : <LocalizedDateDisplay value={ex.ends_on} />}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[ex.status] ?? ''}`}>
                                                    {ex.status_label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {ex.deleted_at
                                                        ? ex.can.restore && <button onClick={() => router.post(route('cafeteria.employee-exclusions.restore', ex.id))} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{t('common.restore')}</button>
                                                        : <>
                                                            <Link href={route('cafeteria.employee-exclusions.show', ex.id)} className="text-sm text-gray-500 hover:underline dark:text-slate-400">{t('common.view')}</Link>
                                                            {ex.can.update && <Link href={route('cafeteria.employee-exclusions.edit', ex.id)} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{t('common.edit')}</Link>}
                                                            {ex.can.end && ex.status === 'active' && <button onClick={() => handleEnd(ex.id)} className="text-sm text-amber-600 hover:underline dark:text-amber-400">{t('cafeteria.endExclusion')}</button>}
                                                            {ex.can.archive && <button onClick={() => handleArchive(ex.id)} className="text-sm text-red-500 hover:underline">{t('common.delete')}</button>}
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
            </div>
        </AuthenticatedLayout>
    );
}
