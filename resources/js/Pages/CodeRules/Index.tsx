import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleActionMenu from '@/Components/code-rules/CodeRuleActionMenu';
import CodeRuleEntityTypeBadge from '@/Components/code-rules/CodeRuleEntityTypeBadge';
import CodeRuleStatusBadge from '@/Components/code-rules/CodeRuleStatusBadge';

type RuleRow = {
    id: string;
    entity_type: string;
    scope_label: string | null;
    name_en: string;
    prefix: string | null;
    format: string;
    next_number: number;
    reset_frequency: string;
    is_active: boolean;
    preview: string;
    can: { view: boolean; update: boolean; archive: boolean; restore: boolean };
};

type Options = {
    entity_types: Array<{ value: string; label_key: string }>;
    scope_types: Array<{ value: string; label_key: string }>;
    reset_frequencies: Array<{ value: string; label_key: string }>;
};

export default function CodeRulesIndex({
    codeRules,
    filters,
    options,
    can,
}: {
    codeRules: { data: RuleRow[]; meta: { current_page: number; last_page: number; total: number } };
    filters: Record<string, string>;
    options: Options;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const form = useForm({
        search: filters.search ?? '',
        entity_type: filters.entity_type ?? '',
        scope_type: filters.scope_type ?? '',
        is_active: filters.is_active ?? '',
        reset_frequency: filters.reset_frequency ?? '',
    });

    const inputClassName =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('code-rules.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('codeRules.title')}
                    description={t('codeRules.description')}
                    actions={can.create ? (
                        <Link href={route('code-rules.create')} className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                            <Plus className="h-3.5 w-3.5" />
                            {t('codeRules.createTitle')}
                        </Link>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('codeRules.title')} />

            <div className="space-y-6">
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <form className="grid gap-3 xl:grid-cols-5" onSubmit={submit}>
                        <input className={inputClassName} value={form.data.search} placeholder={t('codeRules.searchPlaceholder')} onChange={(event) => form.setData('search', event.target.value)} />
                        <select className={inputClassName} value={form.data.entity_type} onChange={(event) => form.setData('entity_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.entityType')}</option>
                            {options.entity_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select>
                        <select className={inputClassName} value={form.data.scope_type} onChange={(event) => form.setData('scope_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.scopeType')}</option>
                            {options.scope_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select>
                        <select className={inputClassName} value={form.data.reset_frequency} onChange={(event) => form.setData('reset_frequency', event.target.value)}>
                            <option value="">{t('codeRules.filters.resetFrequency')}</option>
                            {options.reset_frequencies.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select>
                        <div className="flex gap-3">
                            <select className={`${inputClassName} flex-1`} value={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.value)}>
                                <option value="">{t('codeRules.filters.status')}</option>
                                <option value="1">{t('codeRules.statusActive')}</option>
                                <option value="0">{t('codeRules.statusInactive')}</option>
                            </select>
                            <button className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]" type="submit">
                                {t('common.filter')}
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {codeRules.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('codeRules.noRules')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        {[t('codeRules.name'), t('codeRules.entityType'), t('codeRules.scope'), t('codeRules.prefix'), t('codeRules.format'), t('codeRules.nextNumber'), t('codeRules.resetFrequency'), t('common.status'), t('codeRules.previewCode'), ''].map((heading) => (
                                            <th key={heading} className="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {codeRules.data.map((ruleRow) => (
                                        <tr key={ruleRow.id} className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200">
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{ruleRow.name_en}</td>
                                            <td className="px-4 py-3"><CodeRuleEntityTypeBadge entityType={ruleRow.entity_type} /></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-300">{ruleRow.scope_label ?? t('codeRules.globalRule')}</td>
                                            <td className="px-4 py-3 font-mono text-xs">{ruleRow.prefix ?? '—'}</td>
                                            <td className="px-4 py-3 font-mono text-xs">{ruleRow.format}</td>
                                            <td className="px-4 py-3 tabular-nums">{ruleRow.next_number}</td>
                                            <td className="px-4 py-3">{t(`codeRules.resetFrequencies.${ruleRow.reset_frequency}`)}</td>
                                            <td className="px-4 py-3"><CodeRuleStatusBadge isActive={ruleRow.is_active} /></td>
                                            <td className="px-4 py-3 font-mono text-xs text-blue-700 dark:text-blue-300">{ruleRow.preview}</td>
                                            <td className="px-4 py-3"><CodeRuleActionMenu rule={ruleRow} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="flex items-center justify-between text-sm text-gray-600 dark:text-slate-300">
                    <span>{codeRules.meta.total} total</span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={codeRules.meta.current_page <= 1}
                            onClick={() => router.get(route('code-rules.index'), { ...form.data, page: codeRules.meta.current_page - 1 }, { preserveState: true, preserveScroll: true })}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-50 dark:border-slate-700"
                        >
                            Prev
                        </button>
                        <button
                            type="button"
                            disabled={codeRules.meta.current_page >= codeRules.meta.last_page}
                            onClick={() => router.get(route('code-rules.index'), { ...form.data, page: codeRules.meta.current_page + 1 }, { preserveState: true, preserveScroll: true })}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-50 dark:border-slate-700"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
