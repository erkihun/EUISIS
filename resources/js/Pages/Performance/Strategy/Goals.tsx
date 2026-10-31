import PageHeader from '@/Components/PageHeader';
import { Plus } from '@/Components/Icons';
import { Pill, dangerLinkBtn, linkBtn, primaryBtn } from '@/Components/performance/ui';
import { BalanceBadge, Card, ContextFilter, GivenWeight, pct, truncate, wTable, wTd, wTh, type Can, type Cycle, type Filters, type GoalRow, type Named } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Group = { id: string; number: number; title: string; weight_percent: string; goals: GoalRow[] };
type Summary = { goal_count: number; active_count: number; inactive_count: number; objective_count: number; activity_count: number };
type Props = { groups: Group[]; summary: Summary | null; filters: Filters; cycles: Cycle[]; organizations: Named[]; can: Can };

/** WLMS "Strategic Goals" list: goals grouped under their main strategy, each group folding open and shut. */
export default function Goals({ groups, summary, filters, cycles, organizations, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const g = (key: string) => t(`performance.strategy.goals.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const [closed, setClosed] = useState<Record<string, boolean>>({});
    const contextSelected = filters.cycle_id !== '' && filters.organization_id !== '';

    async function remove(goal: GoalRow) {
        const { confirmed } = await confirm({ title: g('deleteConfirm'), description: `${goal.serial} ${goal.strategy_title}`, confirmLabel: c('delete'), cancelLabel: c('cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategy.goals.destroy', goal.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={g('title')} description={g('subtitle')}
            actions={can.create && can.plan && contextSelected ? <Link href={route('performance.strategy.goals.create', filters)} className={primaryBtn}><Plus className="h-4 w-4" aria-hidden="true" />{g('add')}</Link> : undefined} />}>
            <Head title={g('title')} />
            <div className="min-w-0 space-y-4">
                <ContextFilter routeName="performance.strategy.goals.index" filters={filters} cycles={cycles} organizations={organizations} />

                {contextSelected && can.create && !can.plan && <p role="status" className="text-sm text-amber-800 dark:text-amber-300">{t('performance.strategy.noPlanRight')}</p>}

                {summary && (
                    <div className="grid gap-3 sm:grid-cols-4">
                        <Card label={g('totalGoals')}>{summary.goal_count}</Card>
                        <Card label={g('active')}>{summary.active_count}</Card>
                        <Card label={g('objectivesLabel')}>{summary.objective_count}</Card>
                        <Card label={g('activitiesLabel')}>
                            {summary.activity_count}
                            <p className="text-xs font-normal text-(--app-muted-foreground)">{g('inactive')}: {summary.inactive_count}</p>
                        </Card>
                    </div>
                )}

                {contextSelected && (
                    <div className="overflow-x-auto rounded-panel border border-(--app-border) bg-(--app-surface)">
                        <table className={`${wTable} min-w-[1100px]`}>
                            <thead>
                                <tr>
                                    <th className={wTh}>{g('titleLabel')}</th>
                                    <th className={`${wTh} w-40`}>{g('goalWeight')}</th>
                                    <th className={wTh}>{g('objectivesLabel')}</th>
                                    <th className={`${wTh} w-28`}>{g('activitiesLabel')}</th>
                                    <th className={`${wTh} w-36`}>{g('weightColumn')}</th>
                                    <th className={`${wTh} w-32`}>{g('status')}</th>
                                    <th className={`${wTh} w-40`}>{c('actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.length === 0 && <tr><td colSpan={7} className={`${wTd} text-center text-(--app-muted-foreground)`}>{g('noTasks')}</td></tr>}
                                {groups.map((group) => (
                                    <GroupRows key={group.id} group={group} open={!closed[group.id]} toggle={() => setClosed({ ...closed, [group.id]: !closed[group.id] })}
                                        can={can} onDelete={remove} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function GroupRows({ group, open, toggle, can, onDelete }: { group: Group; open: boolean; toggle: () => void; can: Can; onDelete: (goal: GoalRow) => void }) {
    const { t } = useLocale();
    const g = (key: string) => t(`performance.strategy.goals.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);

    return (
        <>
            <tr className="cursor-pointer bg-(--app-surface-muted)/60" onClick={toggle}>
                <td colSpan={7} className={`${wTd} font-semibold`}>
                    <button type="button" className="mr-2 inline-flex w-5 justify-center" aria-expanded={open} onClick={(e) => { e.stopPropagation(); toggle(); }}>{open ? '−' : '+'}</button>
                    {group.number}. {g('mainStrategyGroup')}: {group.title}
                    <span className="ml-1 font-normal text-(--app-muted-foreground)">({group.goals.length})</span>
                    <span className="ml-3 text-xs font-normal text-(--app-muted-foreground)">{g('givenMainWeight')}: {pct(group.weight_percent)}%</span>
                </td>
            </tr>
            {open && group.goals.map((goal) => (
                <tr key={goal.id}>
                    <td className={wTd}>
                        <p className="font-medium">{goal.serial} {goal.strategy_title}</p>
                        <p className="text-xs text-(--app-muted-foreground)">{truncate(goal.description, 90) || c('none')}</p>
                    </td>
                    <td className={wTd}><GivenWeight given={goal.strategy_weight} share={100} label={g('givenWeight')} /></td>
                    <td className={wTd}>
                        {goal.objectives.length === 0 ? c('none') : (
                            <div className="space-y-1 text-xs">
                                {goal.objectives.map((o) => (
                                    <div key={o.id} className="flex flex-wrap justify-between gap-2">
                                        <span>{o.serial}. {truncate(o.title, 90)} ({o.activities.length}) <span className="text-(--app-muted-foreground) tabular-nums">· {g('progress')}: {pct(o.progress)}%</span></span>
                                        <GivenWeight given={o.weight_percent} share={o.normalized} label={g('givenWeight')} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </td>
                    <td className={`${wTd} tabular-nums`}>{goal.activities_count}</td>
                    <td className={wTd}>
                        <p className="font-semibold tabular-nums">{pct(goal.normalized_total)}%</p>
                        <p className="text-xs text-(--app-muted-foreground) tabular-nums">{g('progress')}: {pct(goal.progress)}%</p>
                        <BalanceBadge balanced={goal.balanced} labels={{ balanced: g('balanced'), needsFix: g('needsFix') }} />
                    </td>
                    <td className={wTd}>
                        <p className="text-xs font-medium">{goal.is_active ? g('active') : g('inactive')}</p>
                        <div className="mt-1"><Pill group="goal" value={goal.status} /></div>
                    </td>
                    <td className={wTd}>
                        <div className="flex flex-wrap gap-3">
                            <Link href={route('performance.strategy.goals.show', goal.id)} className={linkBtn}>{c('view')}</Link>
                            {goal.status === 'DRAFT' && can.update && can.plan && <Link href={route('performance.strategy.goals.edit', goal.id)} className={linkBtn}>{c('edit')}</Link>}
                            {goal.status === 'DRAFT' && can.delete && can.plan && <button type="button" className={dangerLinkBtn} onClick={() => onDelete(goal)}>{c('delete')}</button>}
                        </div>
                    </td>
                </tr>
            ))}
        </>
    );
}
