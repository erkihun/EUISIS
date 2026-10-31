import PageHeader from '@/Components/PageHeader';
import { FieldError, Problems, dangerLinkBtn, fill, inputCls, labelCls, primaryBtn, secondaryBtn, smallBtn, textareaCls } from '@/Components/performance/ui';
import { boxCls, contextLine, off, ok, pct, units, wTable, wTd, wTh, type Context } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type ActivityInput = { key: string; id: string | null; title: string; weight_percent: string; time_minutes: string };
type ObjectiveInput = { key: string; id: string | null; title: string; weight_percent: string; activities: ActivityInput[] };
type Group = { id: string; number: number; title: string; weight_percent: string; strategies: { id: string; title: string; weight_percent: string; order_no: number }[] };
type Props = {
    goal: {
        id: string; code: string; strategy_id: string; weight_percent: string; description: string | null; is_active: boolean; status: string;
        objectives: { id: string; title: string; weight_percent: string; activities: { id: string; title: string; weight_percent: string; time_minutes: number | null }[] }[];
    } | null;
    strategyId: string | null;
    groups: Group[];
    plan: { id: string; title: string; status: string } | null;
    editable: boolean;
    canPlan: boolean;
    context: Context;
};

let counter = 0;
const newKey = () => `row-${++counter}`;
const asText = (value: string | number) => String(Number(value));
const RENDERED = /^(strategy_id|description|is_active|objectives|objectives\.\d+\.(title|weight_percent|activities|activities\.\d+\.(title|weight_percent|time_minutes)))$/;

/**
 * WLMS "Strategic Goal Creation Template": pick a strategy (its weight is the
 * goal's), then objectives (#M.S.O) with their activities (M.S.O.A).
 */
export default function GoalForm({ goal, strategyId, groups, plan, editable, canPlan, context }: Props) {
    const { t, locale } = useLocale();
    const g = (key: string) => t(`performance.strategy.goals.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const activity = (weight = ''): ActivityInput => ({ key: newKey(), id: null, title: '', weight_percent: weight, time_minutes: '' });
    const objective = (weight = '', first = false): ObjectiveInput => ({
        key: newKey(), id: null, title: '', weight_percent: weight,
        activities: first ? [activity('50'), activity('50')] : [activity(), activity()],
    });
    const strategies = groups.flatMap((group) => group.strategies.map((s) => ({ ...s, main: group })));
    const initial = strategies.find((s) => s.id === (goal?.strategy_id ?? strategyId)) ?? null;
    const form = useForm({
        strategy_id: initial?.id ?? '',
        description: goal?.description ?? '',
        is_active: goal?.is_active ?? true,
        objectives: goal && goal.objectives.length > 0
            ? goal.objectives.map((o) => ({
                key: newKey(), id: o.id, title: o.title, weight_percent: asText(o.weight_percent),
                activities: o.activities.map((a) => ({ key: newKey(), id: a.id, title: a.title, weight_percent: asText(a.weight_percent), time_minutes: a.time_minutes === null ? '' : String(a.time_minutes) })),
            }))
            // A new goal starts with one objective holding the whole strategy weight and two activities at 50%.
            : [objective(initial ? asText(initial.weight_percent) : '', true)],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const selected = strategies.find((s) => s.id === form.data.strategy_id) ?? null;
    const goalWeight = selected ? Number(selected.weight_percent) : 0;
    const prefix = selected ? `${selected.main.number}.${selected.order_no}` : '0.0';
    const objectivesTotal = form.data.objectives.reduce((sum, o) => sum + units(o.weight_percent), 0) / 10000;
    const normalizedTotal = goalWeight > 0 ? (objectivesTotal / goalWeight) * 100 : 0;
    const list = route('performance.strategy.goals.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });
    const otherErrors = Object.entries(errors).filter(([key, message]) => message && !RENDERED.test(key)).map(([, message]) => message as string);
    const objectives = form.data.objectives;

    function chooseStrategy(id: string) {
        const next = strategies.find((s) => s.id === id);
        const only = objectives.length === 1 ? objectives[0] : null;
        // The single starting objective follows the strategy's weight until it is edited.
        if (next && only && (only.weight_percent === '' || (selected && units(only.weight_percent) === units(selected.weight_percent)))) {
            form.setData({ ...form.data, strategy_id: id, objectives: [{ ...only, weight_percent: asText(next.weight_percent) }] });
        } else {
            form.setData('strategy_id', id);
        }
    }

    function setObjective(index: number, patch: Partial<ObjectiveInput>) {
        form.setData('objectives', objectives.map((o, i) => (i === index ? { ...o, ...patch } : o)));
    }

    function setActivity(oIndex: number, aIndex: number, field: 'title' | 'weight_percent' | 'time_minutes', value: string) {
        setObjective(oIndex, { activities: objectives[oIndex].activities.map((a, i) => (i === aIndex ? { ...a, [field]: value } : a)) });
    }

    function removeActivity(oIndex: number, aIndex: number) {
        const rest = objectives[oIndex].activities.filter((_, i) => i !== aIndex);
        // An objective always keeps at least two activity rows.
        while (rest.length < 2) rest.push(activity());
        setObjective(oIndex, { activities: rest });
    }

    function removeObjective(index: number) {
        const rest = objectives.filter((_, i) => i !== index);
        form.setData('objectives', rest.length > 0 ? rest : [objective()]);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...(goal ? {} : { strategy_id: data.strategy_id }),
            description: data.description || null,
            is_active: data.is_active,
            objectives: data.objectives.map((o) => ({
                id: o.id, title: o.title, weight_percent: o.weight_percent,
                activities: o.activities.map((a) => ({ id: a.id, title: a.title, weight_percent: a.weight_percent, time_minutes: a.time_minutes === '' ? null : Number(a.time_minutes) })),
            })),
        }));
        if (goal) form.put(route('performance.strategy.goals.update', goal.id));
        else form.post(route('performance.strategy.goals.store'));
    }

    const heading = goal ? `${g('editTitle')}: ${prefix} ${selected?.title ?? goal.code}` : g('createTitle');

    return (
        <AuthenticatedLayout header={<PageHeader title={heading} description={`${g('createSubtitle')} · ${contextLine(context, locale)}`}
            actions={<Link href={list} className={secondaryBtn}>{g('backToList')}</Link>} />}>
            <Head title={heading} />
            <form onSubmit={submit} className="min-w-0 space-y-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                {!editable && (
                    <div role="status" className="rounded-panel border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                        {canPlan ? t('performance.strategy.locked') : t('performance.strategy.noPlanRight')}
                    </div>
                )}

                <fieldset disabled={!editable} className={`${boxCls} space-y-4`}>
                    <h2 className="text-sm font-semibold">{g('formTemplateTitle')}</h2>
                    <div className="grid gap-3 md:grid-cols-[1fr_16rem]">
                        <div>
                            <label htmlFor="sg-strategy" className={labelCls}>{g('titleLabel')}</label>
                            <select id="sg-strategy" className={inputCls} value={form.data.strategy_id} disabled={goal !== null} onChange={(e) => chooseStrategy(e.target.value)} required>
                                <option value="">{c('select')}</option>
                                {groups.filter((group) => group.strategies.length > 0).map((group) => (
                                    <optgroup key={group.id} label={group.title}>
                                        {group.strategies.map((s) => <option key={s.id} value={s.id}>{s.title}</option>)}
                                    </optgroup>
                                ))}
                            </select>
                            {selected && <p className="mt-1 text-xs text-(--app-muted-foreground)">{g('selectedStrategyWeightLabel')}: {pct(selected.weight_percent)}%</p>}
                            <FieldError message={errors.strategy_id} />
                        </div>
                        <div>
                            <label htmlFor="sg-weight" className={labelCls}>{g('goalWeight')}</label>
                            <input id="sg-weight" className={`${inputCls} bg-(--app-surface-muted)`} value={`${pct(goalWeight)}%`} readOnly tabIndex={-1} />
                        </div>
                    </div>

                    {objectives.map((o, oIndex) => {
                        const total = o.activities.reduce((sum, a) => sum + units(a.weight_percent), 0);
                        const normalized = goalWeight > 0 ? ((units(o.weight_percent) / 10000) / goalWeight) * 100 : null;
                        return (
                            <section key={o.key} className="space-y-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold">{g('objective')} #{prefix}.{oIndex + 1}</h3>
                                    <button type="button" className={dangerLinkBtn} onClick={() => removeObjective(oIndex)}>{g('removeObjective')}</button>
                                </div>
                                <div className="grid gap-3 md:grid-cols-[1fr_16rem]">
                                    <div>
                                        <label htmlFor={`ob-${o.key}`} className={labelCls}>{g('objective')}</label>
                                        <textarea id={`ob-${o.key}`} rows={3} className={textareaCls} value={o.title} maxLength={500} onChange={(e) => setObjective(oIndex, { title: e.target.value })} required />
                                        <FieldError message={errors[`objectives.${oIndex}.title`]} />
                                    </div>
                                    <div>
                                        <label htmlFor={`ow-${o.key}`} className={labelCls}>{g('objectiveWeight')}</label>
                                        <div className="flex items-center gap-2">
                                            <input id={`ow-${o.key}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={o.weight_percent} onChange={(e) => setObjective(oIndex, { weight_percent: e.target.value })} required />
                                            <span>%</span>
                                            {normalized !== null && <span className="whitespace-nowrap text-xs text-(--app-muted-foreground) tabular-nums">({pct(normalized)}%)</span>}
                                        </div>
                                        <FieldError message={errors[`objectives.${oIndex}.weight_percent`]} />
                                    </div>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className={`${wTable} min-w-[820px]`}>
                                        <thead>
                                            <tr>
                                                <th className={`${wTh} w-24`}>{g('serialNo')}</th>
                                                <th className={wTh}>{g('activitiesLabel')}</th>
                                                <th className={`${wTh} w-36`}>{g('timeMinutes')}</th>
                                                <th className={`${wTh} w-40`}>{g('weightColumn')}</th>
                                                <th className={`${wTh} w-[60px]`}><span className="sr-only">{c('delete')}</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {o.activities.map((a, aIndex) => {
                                                const serial = `${prefix}.${oIndex + 1}.${aIndex + 1}`;
                                                return (
                                                    <tr key={a.key}>
                                                        <td className={`${wTd} text-center tabular-nums`}>{serial}</td>
                                                        <td className={wTd}>
                                                            <input aria-label={`${g('activitiesLabel')} ${serial}`} className={inputCls} value={a.title} maxLength={500} onChange={(e) => setActivity(oIndex, aIndex, 'title', e.target.value)} required />
                                                            <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.title`]} />
                                                        </td>
                                                        <td className={wTd}>
                                                            <input aria-label={`${g('timeMinutes')} ${serial}`} type="number" step="1" min="0" className={inputCls} value={a.time_minutes} onChange={(e) => setActivity(oIndex, aIndex, 'time_minutes', e.target.value)} />
                                                            <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.time_minutes`]} />
                                                        </td>
                                                        <td className={wTd}>
                                                            <div className="flex items-center gap-2">
                                                                <input aria-label={`${g('weightColumn')} ${serial}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={a.weight_percent} onChange={(e) => setActivity(oIndex, aIndex, 'weight_percent', e.target.value)} required />
                                                                <span>%</span>
                                                            </div>
                                                            <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.weight_percent`]} />
                                                        </td>
                                                        <td className={`${wTd} text-center`}>
                                                            <button type="button" className={dangerLinkBtn} onClick={() => removeActivity(oIndex, aIndex)}>{c('delete')}</button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td className={wTd} />
                                                <td className={`${wTd} font-semibold`}>{g('objectiveTotalWeight')}</td>
                                                <td className={wTd} />
                                                <td className={`${wTd} text-center font-semibold tabular-nums ${total === 1000000 ? ok : off}`}>{pct(total / 10000)}%</td>
                                                <td className={wTd} />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <FieldError message={errors[`objectives.${oIndex}.activities`]} />
                                <div className="flex gap-2">
                                    <button type="button" className={smallBtn} onClick={() => setObjective(oIndex, { activities: [...o.activities, activity()] })}>{g('addItem')}</button>
                                    <button type="button" className={smallBtn} disabled={o.activities.length <= 2} onClick={() => setObjective(oIndex, { activities: o.activities.slice(0, -1) })}>{g('removeItem')}</button>
                                </div>
                            </section>
                        );
                    })}

                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" className={smallBtn} onClick={() => form.setData('objectives', [...objectives, objective()])}>{g('addObjective')}</button>
                        <button type="button" className={smallBtn} disabled={objectives.length <= 1} onClick={() => form.setData('objectives', objectives.slice(0, -1))}>{g('removeObjective')}</button>
                        <span className="text-xs text-(--app-muted-foreground)">{g('totalWeightHint')}</span>
                    </div>
                    <div className="text-sm">
                        <span className="font-medium">{g('objectivesTotalWeight')}:</span>
                        <span className={`ml-2 font-semibold tabular-nums ${goalWeight > 0 && Math.abs(normalizedTotal - 100) < 0.005 ? ok : off}`}>{pct(normalizedTotal)}%</span>
                        <span className="ml-2 text-(--app-muted-foreground)">/ {g('goalWeight')}: <span className="tabular-nums">{pct(goalWeight)}%</span></span>
                    </div>
                    <FieldError message={errors.objectives} />
                </fieldset>

                <fieldset disabled={!editable} className="space-y-4">
                    <div>
                        <label htmlFor="sg-description" className={labelCls}>{g('description')}</label>
                        <textarea id="sg-description" rows={4} className={textareaCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        <FieldError message={errors.description} />
                    </div>
                    <label className="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <span>{g('isActive')}</span>
                    </label>
                </fieldset>

                <Problems title={t('performance.strategicGoals.readiness')} problems={otherErrors} />

                <p className="text-xs text-(--app-muted-foreground)">
                    {plan ? fill(t('performance.strategy.planNote'), { plan: plan.title }) : t('performance.strategy.planNew')} {t('performance.strategy.allocateNext')}
                </p>

                <div className="flex items-center justify-end gap-2">
                    <Link href={list} className={secondaryBtn}>{c('cancel')}</Link>
                    {editable && <button type="submit" className={primaryBtn} disabled={form.processing}>{goal ? c('update') : c('create')}</button>}
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
