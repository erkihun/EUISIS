import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Plus, Layers, CheckCircle, ScrollText, SettingsIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleActionMenu from '@/Components/code-rules/CodeRuleActionMenu';
import CodeRuleEntityTypeBadge from '@/Components/code-rules/CodeRuleEntityTypeBadge';
import CodeRuleStatusBadge from '@/Components/code-rules/CodeRuleStatusBadge';

import { buttonClassName, cx, inputClassName as uiInputClassName } from '@/Components/ui';
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
    summary,
    filters,
    options,
    can,
}: {
    codeRules: { data: RuleRow[]; meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null } };
    summary: { total: number; active: number; scoped: number; entity_types: number };
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

    const inputClassName = cx(uiInputClassName, 'min-w-0 w-full');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('code-rules.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        form.setData({ search: '', entity_type: '', scope_type: '', is_active: '', reset_frequency: '' });
        router.get(route('code-rules.index'), {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('codeRules.title')}
                    description={t('codeRules.description')}
                    actions={can.create ? (
                        <Link href={route('code-rules.create')} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                            <Plus className="h-3.5 w-3.5" />
                            {t('codeRules.createTitle')}
                        </Link>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('codeRules.title')} />

            <div className="space-y-6">
                <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    {[
                        { label: 'totalRules', value: summary.total, icon: ScrollText, color: 'text-blue-600 bg-blue-50 dark:bg-blue-900/20 dark:text-blue-300' },
                        { label: 'activeRules', value: summary.active, icon: CheckCircle, color: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 dark:text-emerald-300' },
                        { label: 'scopedRules', value: summary.scoped, icon: Layers, color: 'text-violet-600 bg-violet-50 dark:bg-violet-900/20 dark:text-violet-300' },
                        { label: 'coveredEntities', value: summary.entity_types, icon: SettingsIcon, color: 'text-amber-600 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-300' },
                    ].map(({ label, value, icon: Icon, color }) => (
                        <div key={label} className="flex items-center gap-4 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 shadow-sm">
                            <span className={`hidden rounded-xl p-3 sm:block ${color}`}><Icon className="h-5 w-5" aria-hidden="true" /></span>
                            <div><p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t(`codeRules.${label}`)}</p><p className="mt-1 text-2xl font-semibold tabular-nums text-[color:var(--app-foreground)]">{value}</p></div>
                        </div>
                    ))}
                </div>
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h2 className="font-semibold text-[color:var(--app-foreground)]">{t('codeRules.filterTitle')}</h2>
                    <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('codeRules.filterDescription')}</p>
                    <form className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" onSubmit={submit}>
                        <div className="sm:col-span-2 xl:col-span-4"><input aria-label={t('codeRules.searchPlaceholder')} className={inputClassName} value={form.data.search} placeholder={t('codeRules.searchPlaceholder')} onChange={(event) => form.setData('search', event.target.value)} /></div>
                        <label className="space-y-2 text-xs font-medium text-[color:var(--app-muted-foreground)]"><span>{t('codeRules.entityType')}</span><select className={inputClassName} value={form.data.entity_type} onChange={(event) => form.setData('entity_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.entityType')}</option>
                            {options.entity_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select></label>
                        <label className="space-y-2 text-xs font-medium text-[color:var(--app-muted-foreground)]"><span>{t('codeRules.scopeType')}</span><select className={inputClassName} value={form.data.scope_type} onChange={(event) => form.setData('scope_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.scopeType')}</option>
                            {options.scope_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select></label>
                        <label className="space-y-2 text-xs font-medium text-[color:var(--app-muted-foreground)]"><span>{t('codeRules.resetFrequency')}</span><select className={inputClassName} value={form.data.reset_frequency} onChange={(event) => form.setData('reset_frequency', event.target.value)}>
                            <option value="">{t('codeRules.filters.resetFrequency')}</option>
                            {options.reset_frequencies.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </select></label>
                        <label className="space-y-2 text-xs font-medium text-[color:var(--app-muted-foreground)]"><span>{t('common.status')}</span>
                            <select className={`${inputClassName} flex-1`} value={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.value)}>
                                <option value="">{t('codeRules.filters.status')}</option>
                                <option value="1">{t('codeRules.statusActive')}</option>
                                <option value="0">{t('codeRules.statusInactive')}</option>
                            </select></label>
                        <div className="flex justify-end gap-3 border-t border-[color:var(--app-border)] pt-4 sm:col-span-2 xl:col-span-4">
                            <button className={buttonClassName({ variant: 'primary' })} type="submit">
                                {t('common.filter')}
                            </button>
                            <button className={buttonClassName({ variant: 'outline' })} type="button" onClick={clearFilters}>
                                {t('common.clear')}
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {codeRules.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('codeRules.noRules')} />
                        </div>
                    ) : (
                        <div className="divide-y divide-[color:var(--app-border)]">
                                    {codeRules.data.map((ruleRow) => (
                                        <article key={ruleRow.id} className="p-5 transition hover:bg-gray-50/60 dark:hover:bg-slate-800/30">
                                            <div className="grid gap-5 lg:grid-cols-3">
                                                <div className="min-w-0">
                                                    <div className="mb-3 flex flex-wrap gap-2"><CodeRuleEntityTypeBadge entityType={ruleRow.entity_type} /><CodeRuleStatusBadge isActive={ruleRow.is_active} /></div>
                                                    <h3 className="break-words font-semibold text-[color:var(--app-foreground)]">{ruleRow.can.view ? <Link className="hover:underline" href={route('code-rules.show', ruleRow.id)}>{ruleRow.name_en}</Link> : ruleRow.name_en}</h3>
                                                    <p className="mt-1 break-words text-xs text-[color:var(--app-muted-foreground)]">{t('codeRules.scope')}: {ruleRow.scope_label ?? t('codeRules.globalRule')}</p>
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="mb-2 text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.ruleConfiguration')}</p>
                                                    <code className="block break-all rounded-lg border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-3 py-2 text-xs leading-5 text-[color:var(--app-foreground)]">{ruleRow.format}</code>
                                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[color:var(--app-muted-foreground)]"><span>{t('codeRules.prefix')}: {ruleRow.prefix || '—'}</span><span>{t('codeRules.resetFrequency')}: {t(`codeRules.resetFrequencies.${ruleRow.reset_frequency}`)}</span></div>
                                                </div>
                                                <div className="min-w-0 rounded-xl border border-blue-100 bg-blue-50/60 p-3 dark:border-blue-900/40 dark:bg-blue-950/20">
                                                    <p className="text-xs font-medium text-blue-700 dark:text-blue-300">{t('codeRules.previewCode')}</p>
                                                    <p className="mt-2 break-all font-mono text-sm font-semibold text-blue-900 dark:text-blue-200">{ruleRow.preview || t('codeRules.previewUnavailable')}</p>
                                                    <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{t('codeRules.nextNumber')}: <span className="tabular-nums">{ruleRow.next_number}</span></p>
                                                </div>
                                            </div>
                                            <div className="mt-4 border-t border-[color:var(--app-border)] pt-3"><CodeRuleActionMenu rule={ruleRow} /></div>
                                        </article>
                                    ))}
                        </div>
                    )}
                </section>

                <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-[color:var(--app-muted-foreground)]">
                    <span>{t('codeRules.showingResults').replace(':from', String(codeRules.meta.from ?? 0)).replace(':to', String(codeRules.meta.to ?? 0)).replace(':total', String(codeRules.meta.total))}</span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={codeRules.meta.current_page <= 1}
                            onClick={() => router.get(route('code-rules.index'), { ...filters, page: codeRules.meta.current_page - 1 }, { preserveState: true, preserveScroll: true })}
                            className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        >
                            {t('common.previous')}
                        </button>
                        <button
                            type="button"
                            disabled={codeRules.meta.current_page >= codeRules.meta.last_page}
                            onClick={() => router.get(route('code-rules.index'), { ...filters, page: codeRules.meta.current_page + 1 }, { preserveState: true, preserveScroll: true })}
                            className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        >
                            {t('common.next')}
                        </button>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
