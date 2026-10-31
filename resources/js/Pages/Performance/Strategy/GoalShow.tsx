import PageHeader from '@/Components/PageHeader';
import { Pill, dangerBtn, primaryBtn, secondaryBtn } from '@/Components/performance/ui';
import { BalanceBadge, GivenWeight, contextLine, off, ok, pct, units, type Can, type Context, type GoalRow } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';

type Props = { goal: GoalRow & { main_title: string; main_weight: string }; can: Can; context: Context };

/** WLMS "Strategic Goal Detail". */
export default function GoalShow({ goal, can, context }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const g = (key: string) => t(`performance.strategy.goals.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const list = route('performance.strategy.goals.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });
    const editable = goal.status === 'DRAFT' && can.plan;

    async function remove() {
        const { confirmed } = await confirm({ title: g('deleteConfirm'), description: `${goal.serial} ${goal.strategy_title}`, confirmLabel: c('delete'), cancelLabel: c('cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategy.goals.destroy', goal.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={g('showTitle')} description={contextLine(context, locale)}
            actions={<Link href={list} className={secondaryBtn}>{g('backToList')}</Link>} />}>
            <Head title={g('showTitle')} />
            <div className="min-w-0 space-y-4">
                <div className="grid gap-2 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 text-sm sm:grid-cols-2">
                    <div><span className="font-medium">{g('mainStrategyGroup')}:</span> {goal.main_title}</div>
                    <div><span className="font-medium">{g('givenMainWeight')}:</span> <span className="tabular-nums">{pct(goal.main_weight)}%</span></div>
                    <div><span className="font-medium">{t('performance.strategy.main.strategyTitle')}:</span> {goal.serial}. {goal.strategy_title}</div>
                    <div><span className="font-medium">{g('givenWeight')}:</span> <span className="tabular-nums">{pct(goal.strategy_weight)}%</span></div>
                </div>

                <div className="grid gap-2 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 text-sm sm:grid-cols-3">
                    <div><span className="font-medium">{g('serialNo')}:</span> {goal.code}</div>
                    <div><span className="font-medium">{g('goalWeight')}:</span> <GivenWeight given={goal.strategy_weight} share={100} label={g('givenWeight')} /></div>
                    <div className="flex flex-wrap items-center gap-2"><span className="font-medium">{g('status')}:</span> {goal.is_active ? g('active') : g('inactive')} <Pill group="goal" value={goal.status} /></div>
                    <div><span className="font-medium">{g('objectivesLabel')}:</span> {goal.objectives.length}</div>
                    <div><span className="font-medium">{g('activitiesLabel')}:</span> {goal.activities_count}</div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{g('objectivesTotalWeight')}:</span>
                        <GivenWeight given={goal.objectives_total} share={goal.normalized_total} label={g('givenWeight')} />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{g('weightHealth')}:</span>
                        <BalanceBadge balanced={goal.balanced} labels={{ balanced: g('balanced'), needsFix: g('needsFix') }} />
                    </div>
                </div>

                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                    <h2 className="text-sm font-semibold">{g('description')}</h2>
                    <p className="mt-1 whitespace-pre-line text-sm text-(--app-muted-foreground)">{goal.description || c('none')}</p>
                </section>

                <section className="space-y-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                    <h2 className="text-sm font-semibold">{g('objectivesAndActivities')}</h2>
                    {goal.objectives.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{c('none')}</p>}
                    {goal.objectives.map((o) => (
                        <section key={o.id} className="rounded-panel border border-(--app-border) p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h3 className="text-sm font-semibold">{o.serial}. {o.title}</h3>
                                <div className="flex flex-wrap gap-3 text-xs">
                                    <GivenWeight given={o.weight_percent} share={o.normalized} label={g('givenWeight')} />
                                    <span className={units(o.activities_total) === 1000000 ? ok : off}>{g('activitiesLabel')}: {pct(o.activities_total)}%</span>
                                </div>
                            </div>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{g('activitiesLabel')}: {o.activities.length}</p>
                            {o.activities.length === 0 ? <p className="text-sm">{c('none')}</p> : (
                                <ul className="mt-2 divide-y divide-(--app-border) text-sm">
                                    {o.activities.map((a) => (
                                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                                            <span>{a.serial} {a.title}</span>
                                            <span className="flex gap-3 tabular-nums">
                                                <span className="text-xs text-(--app-muted-foreground)">{g('timeMinutes')}: {a.time_minutes ?? 0}</span>
                                                <span className="font-semibold">{pct(a.weight_percent)}%</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </section>

                <div className="flex justify-end gap-2">
                    {editable && can.update && <Link href={route('performance.strategy.goals.edit', goal.id)} className={primaryBtn}>{c('edit')}</Link>}
                    {editable && can.delete && <button type="button" className={dangerBtn} onClick={remove}>{c('delete')}</button>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
