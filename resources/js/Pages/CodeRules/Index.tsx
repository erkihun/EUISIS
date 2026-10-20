import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Layers, CheckCircle, ScrollText, SettingsIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleActionMenu from '@/Components/code-rules/CodeRuleActionMenu';
import CodeRuleEntityTypeBadge from '@/Components/code-rules/CodeRuleEntityTypeBadge';
import CodeRuleStatusBadge from '@/Components/code-rules/CodeRuleStatusBadge';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, tableContainerClassName } from '@/Components/ui';
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

    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('code-rules.index'), form.data, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        form.setData({ search: '', entity_type: '', scope_type: '', is_active: '', reset_frequency: '' });
        router.get(route('code-rules.index'), {}, { preserveScroll: true });
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
                        <div key={label} className="flex items-center gap-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5 shadow-xs">
                            <span className={`hidden rounded-panel p-3 sm:block ${color}`}><Icon className="h-5 w-5" aria-hidden="true" /></span>
                            <div><p className="text-xs font-medium text-(--app-muted-foreground)">{t(`codeRules.${label}`)}</p><p className="mt-1 text-2xl font-semibold tabular-nums text-(--app-foreground)">{value}</p></div>
                        </div>
                    ))}
                </div>
                <FilterPanel onSubmit={submit} onReset={clearFilters} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={form.data.search} placeholder={t('codeRules.searchPlaceholder')} onChange={(event) => form.setData('search', event.target.value)} />
                    </FilterField>
                    <FilterField label={t('codeRules.entityType')}>
                        <Select value={form.data.entity_type} onChange={(event) => form.setData('entity_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.entityType')}</option>
                            {options.entity_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('codeRules.scopeType')}>
                        <Select value={form.data.scope_type} onChange={(event) => form.setData('scope_type', event.target.value)}>
                            <option value="">{t('codeRules.filters.scopeType')}</option>
                            {options.scope_types.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('codeRules.resetFrequency')}>
                        <Select value={form.data.reset_frequency} onChange={(event) => form.setData('reset_frequency', event.target.value)}>
                            <option value="">{t('codeRules.filters.resetFrequency')}</option>
                            {options.reset_frequencies.map((option) => (
                                <option key={option.value} value={option.value}>{t(option.label_key)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.value)}>
                            <option value="">{t('codeRules.filters.status')}</option>
                            <option value="1">{t('codeRules.statusActive')}</option>
                            <option value="0">{t('codeRules.statusInactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <section className={tableContainerClassName}>
                    {codeRules.data.length === 0 ? (
                        <EmptyState title={t('codeRules.noRules')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="divide-y divide-(--app-border)">
                                    {codeRules.data.map((ruleRow) => (
                                        <article key={ruleRow.id} className="p-5 transition hover:bg-gray-50/60 dark:hover:bg-slate-800/30">
                                            <div className="grid gap-5 lg:grid-cols-3">
                                                <div className="min-w-0">
                                                    <div className="mb-3 flex flex-wrap gap-2"><CodeRuleEntityTypeBadge entityType={ruleRow.entity_type} /><CodeRuleStatusBadge isActive={ruleRow.is_active} /></div>
                                                    <h3 className="wrap-break-word font-semibold text-(--app-foreground)">{ruleRow.can.view ? <Link className="hover:underline" href={route('code-rules.show', ruleRow.id)}>{ruleRow.name_en}</Link> : ruleRow.name_en}</h3>
                                                    <p className="mt-1 wrap-break-word text-xs text-(--app-muted-foreground)">{t('codeRules.scope')}: {ruleRow.scope_label ?? t('codeRules.globalRule')}</p>
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="mb-2 text-xs font-medium text-(--app-muted-foreground)">{t('codeRules.ruleConfiguration')}</p>
                                                    <code className="block break-all rounded-lg border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-xs leading-5 text-(--app-foreground)">{ruleRow.format}</code>
                                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-(--app-muted-foreground)"><span>{t('codeRules.prefix')}: {ruleRow.prefix || '—'}</span><span>{t('codeRules.resetFrequency')}: {t(`codeRules.resetFrequencies.${ruleRow.reset_frequency}`)}</span></div>
                                                </div>
                                                <div className="min-w-0 rounded-panel border border-blue-100 bg-blue-50/60 p-3 dark:border-blue-900/40 dark:bg-blue-950/20">
                                                    <p className="text-xs font-medium text-blue-700 dark:text-blue-300">{t('codeRules.previewCode')}</p>
                                                    <p className="mt-2 break-all font-mono text-sm font-semibold text-blue-900 dark:text-blue-200">{ruleRow.preview || t('codeRules.previewUnavailable')}</p>
                                                    <p className="mt-2 text-xs text-(--app-muted-foreground)">{t('codeRules.nextNumber')}: <span className="tabular-nums">{ruleRow.next_number}</span></p>
                                                </div>
                                            </div>
                                            <div className="mt-4 border-t border-(--app-border) pt-3"><CodeRuleActionMenu rule={ruleRow} /></div>
                                        </article>
                                    ))}
                        </div>
                    )}
                    <ListPagination meta={codeRules.meta} />
                </section>

            </div>
        </AuthenticatedLayout>
    );
}
