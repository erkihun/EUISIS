import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import CodeGenerationLogsTable from '@/Components/code-rules/CodeGenerationLogsTable';
import CodeRuleEntityTypeBadge from '@/Components/code-rules/CodeRuleEntityTypeBadge';
import CodeRulePreviewCard from '@/Components/code-rules/CodeRulePreviewCard';
import CodeRuleStatusBadge from '@/Components/code-rules/CodeRuleStatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import { usesRandomToken } from '@/Components/code-rules/randomTokens';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
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
    const { confirm } = useConfirm();

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
                                <Link href={route('code-rules.edit', codeRule.id)} className={buttonClassName({ variant: 'primary' })}>
                                    {t('common.edit')}
                                </Link>
                            )}
                            {codeRule.can.archive && codeRule.is_active && (
                                <button type="button" onClick={() => router.post(route('code-rules.archive', codeRule.id))} className={buttonClassName({ variant: 'destructive' })}>
                                    {t('codeRules.actions.archive')}
                                </button>
                            )}
                            {codeRule.can.restore && !codeRule.is_active && (
                                <button type="button" onClick={() => router.post(route('code-rules.restore', codeRule.id))} className={buttonClassName({ variant: 'primary' })}>
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
                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.entityType')}</p>
                                <div className="mt-2"><CodeRuleEntityTypeBadge entityType={codeRule.entity_type} /></div>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('common.status')}</p>
                                <div className="mt-2"><CodeRuleStatusBadge isActive={codeRule.is_active} /></div>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.scope')}</p>
                                <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{codeRule.scope_label ?? t('codeRules.globalRule')}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.resetFrequency')}</p>
                                <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{t(`codeRules.resetFrequencies.${codeRule.reset_frequency}` as Parameters<typeof t>[0])}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.prefix')}</p>
                                <p className="mt-1 font-mono text-sm text-[color:var(--app-foreground)]">{codeRule.prefix ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.suffix')}</p>
                                <p className="mt-1 font-mono text-sm text-[color:var(--app-foreground)]">{codeRule.suffix ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.sequenceLength')}</p>
                                <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{codeRule.sequence_length}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.sequenceScopeStrategy')}</p>
                                <p className="mt-1 flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
                                    {strategyLabel}
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${isGlobal ? 'bg-gray-100 text-[color:var(--app-foreground)] dark:bg-slate-700' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'}`}>
                                        {isGlobal ? t('codeRules.globalSequenceBadge') : t('codeRules.scopedSequenceBadge')}
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div className="mt-6">
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.format')}</p>
                            <p className="mt-1 font-mono text-sm text-[color:var(--app-foreground)]">{codeRule.format}</p>
                        </div>

                        <div className="mt-6 grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.descriptionEn')}</p>
                                <p className="mt-1 text-sm leading-6 text-[color:var(--app-foreground)]">{codeRule.description_en ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('codeRules.descriptionAm')}</p>
                                <p className="mt-1 text-sm leading-6 text-[color:var(--app-foreground)]">{codeRule.description_am ?? '—'}</p>
                            </div>
                        </div>
                    </div>

                    {/* Sequence Counters */}
                    {can.viewSequences && (
                        <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                            <h3 className="mb-4 text-sm font-semibold text-[color:var(--app-foreground)]">{t('codeRules.sequenceCounters')}</h3>
                            {sequences.length === 0 ? (
                                <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('codeRules.noSequencesYet')}</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className={tableClassName}>
                                        <thead className={tableHeadClassName}>
                                            <tr className="text-left">
                                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('codeRules.scopeKey')}</th>
                                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('codeRules.nextNumber')}</th>
                                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('codeRules.lastNumber')}</th>
                                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('codeRules.lastGeneratedCode')}</th>
                                                {can.resetSequence && <th className={cx(tableHeaderCellClassName, 'text-left')} />}
                                            </tr>
                                        </thead>
                                        <tbody className={tableBodyClassName}>
                                            {sequences.map((seq) => (
                                                <tr key={seq.id}>
                                                    <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-foreground)]')}>{seq.scope_key}</td>
                                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{seq.next_number}</td>
                                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{seq.last_number ?? '—'}</td>
                                                    <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-foreground)]')}>{seq.last_generated_code ?? '—'}</td>
                                                    {can.resetSequence && (
                                                        <td className={tableCellClassName}>
                                                            <button
                                                                type="button"
                                                                className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                                onClick={async () => {
                                                                    const result = await confirm({ title: t('codeRules.resetSequence'), description: t('codeRules.resetSequence') + '?', variant: 'warning' });
                                                                    if (result.confirmed) {
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
                        <h3 className="mb-3 text-sm font-semibold text-[color:var(--app-foreground)]">{t('codeRules.logs')}</h3>
                        <CodeGenerationLogsTable logs={generationLogs} />
                    </div>
                </section>

                <aside className="space-y-6">
                    <CodeRulePreviewCard
                        preview={codeRule.preview}
                        usesRandomToken={usesRandomToken(codeRule.format)}
                    />
                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                        <div className="space-y-3 text-sm text-[color:var(--app-foreground)]">
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
