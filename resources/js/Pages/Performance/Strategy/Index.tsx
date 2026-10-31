import PageHeader from '@/Components/PageHeader';
import { Plus } from '@/Components/Icons';
import { Empty, Field, Pill, Section, Stat, Table, dangerLinkBtn, fill, formatScore, inputCls, linkBtn, nameOf, primaryBtn, tdCls, thCls, useEnumLabel } from '@/Components/performance/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';

type Named = { id: string; name_en: string; name_am?: string | null };
type Cycle = Named & { code: string; organization_id: string | null; status: string; is_current: boolean };
type GoalSummary = { id: string; code: string; status: string; objectives_count: number; activities_count: number; objectives_total: string };
type StrategyRow = { id: string; number: string; title: string; description: string | null; weight_percent: string; share_of_main: string | null; goal: GoalSummary | null };
type MainRow = { id: string; number: number; title: string; description: string | null; weight_percent: string; strategies_total: string; strategies: StrategyRow[] };
type Summary = { main_count: number; strategy_count: number; goal_count: number; objective_count: number; activity_count: number; main_total: string; remaining: string };
type Props = {
    mains: MainRow[]; summary: Summary | null; filters: { cycle_id: string; organization_id: string };
    cycles: Cycle[]; organizations: Named[]; can: { create: boolean; update: boolean; delete: boolean; plan: boolean };
};

/** Two weights are the same when they agree to four decimals (the stored scale). */
const same = (a: string | number, b: string | number) => Math.round(Number(a) * 10000) === Math.round(Number(b) * 10000);
const ok = 'text-emerald-700 dark:text-emerald-400';
const off = 'text-red-700 dark:text-red-400';

export default function StrategyIndex({ mains, summary, filters, cycles, organizations, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const contextSelected = filters.cycle_id !== '' && filters.organization_id !== '';
    const cycleOptions = cycles.filter((c) => !filters.organization_id || c.organization_id === null || c.organization_id === filters.organization_id);

    function filter(key: 'cycle_id' | 'organization_id', value: string) {
        const next = { ...filters, [key]: value };
        if (key === 'organization_id' && next.cycle_id) {
            const chosen = cycles.find((c) => c.id === next.cycle_id);
            if (chosen?.organization_id && chosen.organization_id !== value) next.cycle_id = '';
        }
        router.get(route('performance.strategy.index'), next, { preserveState: true, replace: true });
    }

    async function remove(main: MainRow) {
        const { confirmed } = await confirm({ title: t('performance.strategy.deleteConfirm'), description: `${main.number}. ${main.title}`, confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategy.main.destroy', main.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.strategy.title')} description={t('performance.strategy.description')}
            actions={can.create && contextSelected ? <Link href={route('performance.strategy.main.create', filters)} className={primaryBtn}><Plus className="h-4 w-4" aria-hidden="true" />{t('performance.strategy.addMain')}</Link> : undefined} />}>
            <Head title={t('performance.strategy.title')} />
            <div className="min-w-0 space-y-6">
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) shadow-xs" aria-label={t('performance.strategy.selectContext')}>
                    <div className="grid gap-4 p-5 sm:grid-cols-2 lg:p-6">
                        <Field label={t('performance.fields.organization')} htmlFor="st-organization">
                            <select id="st-organization" className={inputCls} value={filters.organization_id} onChange={(e) => filter('organization_id', e.target.value)}>
                                <option value="">{t('performance.strategicGoals.selectOrganization')}</option>
                                {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('performance.fields.cycle')} htmlFor="st-cycle">
                            <select id="st-cycle" className={inputCls} value={filters.cycle_id} onChange={(e) => filter('cycle_id', e.target.value)}>
                                <option value="">{t('performance.strategicGoals.selectCycle')}</option>
                                {cycleOptions.map((c) => (
                                    <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)} ({label('cycle', c.status)}{c.is_current ? ` · ${t('performance.cycles.current')}` : ''})</option>
                                ))}
                            </select>
                        </Field>
                    </div>
                </section>

                {!contextSelected && <Empty>{t('performance.strategy.selectContext')}</Empty>}

                {contextSelected && summary && (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 2xl:grid-cols-7">
                        <Stat label={t('performance.strategy.mainStrategies')} value={summary.main_count} />
                        <Stat label={t('performance.strategy.strategies')} value={summary.strategy_count} />
                        <Stat label={t('performance.strategy.goalsCount')} value={summary.goal_count} />
                        <Stat label={t('performance.strategy.objectives')} value={summary.objective_count} />
                        <Stat label={t('performance.strategy.activities')} value={summary.activity_count} />
                        <Stat label={t('performance.strategy.mainTotal')} value={summary.main_total} suffix="%"
                            hint={<span className={same(summary.main_total, 100) ? ok : off}>{same(summary.main_total, 100) ? '✓ 100%' : `≠ 100%`}</span>} />
                        <Stat label={t('performance.strategy.remaining')} value={summary.remaining} suffix="%" />
                    </div>
                )}

                {contextSelected && mains.length === 0 && <Empty>{t('performance.strategy.empty')}</Empty>}

                {contextSelected && can.create && !can.plan && mains.length > 0 && (
                    <p role="status" className="text-sm text-amber-800 dark:text-amber-300">{t('performance.strategy.noPlanRight')}</p>
                )}

                {mains.map((main) => {
                    const balanced = same(main.strategies_total, main.weight_percent);
                    return (
                        <Section key={main.id} flush title={`${main.number}. ${main.title}`} description={main.description ?? undefined}
                            actions={<>
                                <span className="text-sm font-semibold tabular-nums">{formatScore(main.weight_percent)}%</span>
                                <Link href={route('performance.strategy.main.show', main.id)} className={linkBtn}>{t('performance.strategy.common.view')}</Link>
                                {can.update && <Link href={route('performance.strategy.main.edit', main.id)} className={linkBtn}>{t('performance.actions.edit')}</Link>}
                                {can.delete && <button type="button" className={dangerLinkBtn} onClick={() => remove(main)}>{t('performance.actions.remove')}</button>}
                            </>}>
                            <Table head={<>
                                <th className={thCls}>{t('performance.strategy.serialNo')}</th>
                                <th className={thCls}>{t('performance.strategy.strategy')}</th>
                                <th className={thCls}>{t('performance.strategy.givenWeight')}</th>
                                <th className={thCls}>{t('performance.strategy.shareOfMain')}</th>
                                <th className={thCls}>{t('performance.strategy.goal')}</th>
                                <th className={thCls}>{t('performance.strategy.objectivesTotal')}</th>
                                <th className={thCls}>{t('performance.strategy.activities')}</th>
                                <th className={thCls}><span className="sr-only">{t('performance.actions.open')}</span></th>
                            </>}>
                                {main.strategies.map((strategy) => (
                                    <tr key={strategy.id}>
                                        <td className={`${tdCls} tabular-nums`}>{strategy.number}</td>
                                        <td className={tdCls}>
                                            <p className="font-medium">{strategy.title}</p>
                                            {strategy.description && <p className="text-xs text-(--app-muted-foreground)">{strategy.description}</p>}
                                        </td>
                                        <td className={`${tdCls} tabular-nums`}>{formatScore(strategy.weight_percent)}%</td>
                                        <td className={`${tdCls} tabular-nums`}>{strategy.share_of_main === null ? '—' : `${formatScore(strategy.share_of_main)}%`}</td>
                                        <td className={tdCls}>
                                            {strategy.goal
                                                ? <span className="inline-flex flex-wrap items-center gap-2"><span className="font-mono text-xs">{strategy.goal.code}</span><Pill group="goal" value={strategy.goal.status} /></span>
                                                : <span className="text-(--app-muted-foreground)">{t('performance.strategy.noGoal')}</span>}
                                        </td>
                                        <td className={`${tdCls} tabular-nums`}>
                                            {strategy.goal ? <span className={same(strategy.goal.objectives_total, strategy.weight_percent) ? ok : off}>
                                                {formatScore(strategy.goal.objectives_total)}% / {formatScore(strategy.weight_percent)}% ({strategy.goal.objectives_count})</span> : '—'}
                                        </td>
                                        <td className={`${tdCls} tabular-nums`}>{strategy.goal ? strategy.goal.activities_count : '—'}</td>
                                        <td className={`${tdCls} text-right`}>
                                            {strategy.goal
                                                ? <Link href={route(strategy.goal.status === 'DRAFT' && can.update && can.plan ? 'performance.strategy.goals.edit' : 'performance.strategy.goals.show', strategy.goal.id)} className={linkBtn}>{strategy.goal.status === 'DRAFT' && can.update && can.plan ? t('performance.strategy.editGoal') : t('performance.strategy.viewGoal')}</Link>
                                                : can.create && can.plan && <Link href={route('performance.strategy.goals.create', { ...filters, strategy_id: strategy.id })} className={linkBtn}>{t('performance.strategy.registerGoal')}</Link>}
                                        </td>
                                    </tr>
                                ))}
                                <tr>
                                    <td className={`${tdCls} font-semibold`} colSpan={2}>{t('performance.strategy.strategiesTotal')}</td>
                                    <td className={`${tdCls} font-semibold tabular-nums ${balanced ? ok : off}`} colSpan={6}>
                                        {formatScore(main.strategies_total)}% / {formatScore(main.weight_percent)}%
                                    </td>
                                </tr>
                            </Table>
                        </Section>
                    );
                })}

                {contextSelected && summary && !same(summary.remaining, 0) && (
                    <p className="text-xs text-(--app-muted-foreground)">{fill(t('performance.strategy.remainingHint'), { value: formatScore(summary.remaining) })}</p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
