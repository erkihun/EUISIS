import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import CodeGenerationLogsTable from '@/Components/code-rules/CodeGenerationLogsTable';
import CodeRuleEntityTypeBadge from '@/Components/code-rules/CodeRuleEntityTypeBadge';
import CodeRulePreviewCard from '@/Components/code-rules/CodeRulePreviewCard';
import CodeRuleStatusBadge from '@/Components/code-rules/CodeRuleStatusBadge';

type CodeRuleView = {
    id: string;
    entity_type: string;
    scope_label: string | null;
    name_en: string;
    name_am: string | null;
    prefix: string | null;
    suffix: string | null;
    format: string;
    separator: string;
    sequence_length: number;
    next_number: number;
    sequence_scope_strategy: string | null;
    reset_frequency: string;
    year_format: string | null;
    is_active: boolean;
    allow_manual_override: boolean;
    require_approval_for_override: boolean;
    description_en: string | null;
    description_am: string | null;
    preview: string;
    logs_count: number;
    can: { update: boolean; archive: boolean; restore: boolean };
};

type CodeGenerationLog = {
    id: string;
    generated_code: string;
    sequence_number: number;
    generated_at: string | null;
    generated_by: { id: string; name: string } | null;
};

type SequenceCounter = {
    id: string;
    scope_key: string;
    scope_values: Record<string, string>;
    next_number: number;
    last_number: number | null;
    last_generated_code: string | null;
    updated_at: string | null;
};

export default function CodeRulesShow({
    codeRule,
    generationLogs,
    sequences = [],
    can,
}: {
    codeRule: CodeRuleView;
    generationLogs: CodeGenerationLog[];
    sequences?: SequenceCounter[];
    can: { preview: boolean; viewSequences: boolean; resetSequence: boolean };
}) {
    const { t } = useLocale();

    const strategyKey = codeRule.sequence_scope_strategy ?? 'auto';
    const strategyLabel = t(`codeRules.scopeStrategies.${strategyKey}` as Parameters<typeof t>[0]);

    const isGlobal = strategyKey === 'global' || (strategyKey === 'auto' && sequences.length <= 1 && sequences[0]?.scope_key === '_global_');

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    backHref={route('code-rules.index')}
                    title={codeRule.name_en}
                    description={t(`codeRules.entityTypes.${codeRule.entity_type}` as Parameters<typeof t>[0])}
                    actions={(
                        <div className="flex gap-3">
                            {codeRule.can.update && (
                                <Link href={route('code-rules.edit', codeRule.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    {t('common.edit')}
                                </Link>
                            )}
                            {codeRule.can.archive && codeRule.is_active && (
                                <button type="button" onClick={() => router.post(route('code-rules.archive', codeRule.id))} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    {t('codeRules.actions.archive')}
                                </button>
                            )}
                            {codeRule.can.restore && !codeRule.is_active && (
                                <button type="button" onClick={() => router.post(route('code-rules.restore', codeRule.id))} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                    {t('codeRules.actions.restore')}
                                </button>
                            )}
                        </div>
                    )}
                />
            )}
        >
            <Head title={codeRule.name_en} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_360px]">
                <section className="space-y-6">
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.entityType')}</p>
                                <div className="mt-2"><CodeRuleEntityTypeBadge entityType={codeRule.entity_type} /></div>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('common.status')}</p>
                                <div className="mt-2"><CodeRuleStatusBadge isActive={codeRule.is_active} /></div>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.scope')}</p>
                                <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{codeRule.scope_label ?? t('codeRules.globalRule')}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.resetFrequency')}</p>
                                <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{t(`codeRules.resetFrequencies.${codeRule.reset_frequency}` as Parameters<typeof t>[0])}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.prefix')}</p>
                                <p className="mt-1 font-mono text-sm text-gray-900 dark:text-slate-100">{codeRule.prefix ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.suffix')}</p>
                                <p className="mt-1 font-mono text-sm text-gray-900 dark:text-slate-100">{codeRule.suffix ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.sequenceLength')}</p>
                                <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{codeRule.sequence_length}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.sequenceScopeStrategy')}</p>
                                <p className="mt-1 flex items-center gap-2 text-sm text-gray-900 dark:text-slate-100">
                                    {strategyLabel}
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${isGlobal ? 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'}`}>
                                        {isGlobal ? t('codeRules.globalSequenceBadge') : t('codeRules.scopedSequenceBadge')}
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div className="mt-6">
                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.format')}</p>
                            <p className="mt-1 font-mono text-sm text-gray-900 dark:text-slate-100">{codeRule.format}</p>
                        </div>

                        <div className="mt-6 grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.descriptionEn')}</p>
                                <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-slate-300">{codeRule.description_en ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.descriptionAm')}</p>
                                <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-slate-300">{codeRule.description_am ?? '—'}</p>
                            </div>
                        </div>
                    </div>

                    {/* Sequence Counters */}
                    {can.viewSequences && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('codeRules.sequenceCounters')}</h3>
                            {sequences.length === 0 ? (
                                <p className="text-sm text-gray-500 dark:text-slate-400">{t('codeRules.noSequencesYet')}</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100 text-left dark:border-slate-700">
                                                <th className="pb-2 pr-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.scopeKey')}</th>
                                                <th className="pb-2 pr-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.nextNumber')}</th>
                                                <th className="pb-2 pr-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.lastNumber')}</th>
                                                <th className="pb-2 pr-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('codeRules.lastGeneratedCode')}</th>
                                                {can.resetSequence && <th className="pb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400" />}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                            {sequences.map((seq) => (
                                                <tr key={seq.id}>
                                                    <td className="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-slate-300">{seq.scope_key}</td>
                                                    <td className="py-2 pr-4 text-gray-900 dark:text-slate-100">{seq.next_number}</td>
                                                    <td className="py-2 pr-4 text-gray-700 dark:text-slate-300">{seq.last_number ?? '—'}</td>
                                                    <td className="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-slate-300">{seq.last_generated_code ?? '—'}</td>
                                                    {can.resetSequence && (
                                                        <td className="py-2">
                                                            <button
                                                                type="button"
                                                                className="rounded-md border border-amber-300 px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-600 dark:text-amber-400 dark:hover:bg-amber-900/30"
                                                                onClick={() => {
                                                                    if (window.confirm(t('codeRules.resetSequence') + '?')) {
                                                                        router.post(route('code-rules.sequences.reset', { codeRule: codeRule.id, sequence: seq.id }), {}, {
                                                                            onSuccess: () => router.reload({ only: ['sequences'] }),
                                                                        });
                                                                    }
                                                                }}
                                                            >
                                                                {t('codeRules.resetSequence')}
                                                            </button>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}

                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('codeRules.logs')}</h3>
                        <CodeGenerationLogsTable logs={generationLogs} />
                    </div>
                </section>

                <aside className="space-y-6">
                    <CodeRulePreviewCard
                        preview={codeRule.preview}
                        usesRandomToken={codeRule.format.includes('{RAND_6}')}
                    />
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="space-y-3 text-sm text-gray-700 dark:text-slate-300">
                            <div className="flex items-center justify-between gap-3">
                                <span>{t('codeRules.manualOverride')}</span>
                                <span>{codeRule.allow_manual_override ? t('common.yes') : t('common.no')}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span>{t('codeRules.requireApprovalForOverride')}</span>
                                <span>{codeRule.require_approval_for_override ? t('common.yes') : t('common.no')}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span>{t('codeRules.logs')}</span>
                                <span>{codeRule.logs_count}</span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
