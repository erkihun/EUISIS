import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Rule = {
    id: string; code: string; name_en: string; subsidy_amount: number;
    currency: string; effective_from: string; effective_to: string | null;
    applies_to: string; is_active: boolean; deleted_at: string | null;
    can: { update: boolean; archive: boolean };
};
type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function SubsidyRulesIndex({ rules, meta, filters, can }: { rules: Rule[]; meta: Meta; filters: Record<string, string>; can: { create: boolean } }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.subsidy-rules.archive', id));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('cafeteria.subsidy-rules.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.subsidyRules')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.subsidy-rules.create')} className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            + {t('cafeteria.addSubsidyRule')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.subsidyRules')} />
            <div className="space-y-4">
                <form className="flex gap-3" onSubmit={submit}>
                    <input name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} className={inputCls} />
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {rules.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.subsidyRule')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.subsidyAmount')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.effectiveFrom')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.effectiveTo')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.appliesTo')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {rules.map((rule) => (
                                        <tr key={rule.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-gray-900 dark:text-slate-100">{rule.name_en}</div>
                                                <div className="font-mono text-xs text-gray-500">{rule.code}</div>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-emerald-600">{rule.subsidy_amount.toFixed(2)} {rule.currency}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={rule.effective_from} /></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={rule.effective_to} /></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">{rule.applies_to}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {rule.can.update && <Link href={route('cafeteria.subsidy-rules.edit', rule.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">{t('common.edit')}</Link>}
                                                    {rule.can.archive && !rule.deleted_at && <button onClick={() => handleArchive(rule.id)} className="text-xs text-red-600 hover:underline">{t('common.archive')}</button>}
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
