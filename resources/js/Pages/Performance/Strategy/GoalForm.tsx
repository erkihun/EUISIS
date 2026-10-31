import PageHeader from '@/Components/PageHeader';
import { Plus } from '@/Components/Icons';
import { Field, FieldError, Pill, Problems, Section, Table, dangerLinkBtn, fill, formatScore, inputCls, nameOf, primaryBtn, secondaryBtn, smallBtn, tdCls, textareaCls, thCls } from '@/Components/performance/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Named = { id: string; name_en: string; name_am?: string | null };
type ActivityInput = { key: string; id: string | null; title: string; weight_percent: string; time_minutes: string };
type ObjectiveInput = { key: string; id: string | null; title: string; weight_percent: string; activities: ActivityInput[] };
type Group = { id: string; number: number; title: string; weight_percent: string; strategies: { id: string; number: string; title: string; weight_percent: string; taken: boolean }[] };
type Props = {
    goal: {
        id: string; code: string; strategy_id: string; weight_percent: string; description: string | null; status: string;
        objectives: { id: string; title: string; weight_percent: string; activities: { id: string; title: string; weight_percent: string; time_minutes: number | null }[] }[];
    } | null;
    strategyId: string | null;
    groups: Group[];
    plan: { id: string; title: string; status: string } | null;
    editable: boolean;
    canPlan: boolean;
    context: { organization_id: string; cycle_id: string; organization: Named | null; cycle: (Named & { code: string }) | null };
};

let counter = 0;
const newKey = () => `row-${++counter}`;
/** Weights summed in ten-thousandths, the stored scale, so 33.33 + 66.67 is exactly 100. */
const units = (value: string | number) => Math.round((parseFloat(String(value)) || 0) * 10000);
const ok = 'text-emerald-700 dark:text-emerald-400';
const off = 'text-red-700 dark:text-red-400';
const asText = (value: string | number) => String(Number(value));
const RENDERED = /^(strategy_id|description|objectives|objectives\.\d+\.(title|weight_percent|activities|activities\.\d+\.(title|weight_percent|time_minutes)))$/;

export default function GoalForm({ goal, strategyId, groups, plan, editable, canPlan, context }: Props) {
    const { t, locale } = useLocale();
    const blankActivity = (): ActivityInput => ({ key: newKey(), id: null, title: '', weight_percent: '', time_minutes: '' });
    const blankObjective = (): ObjectiveInput => ({ key: newKey(), id: null, title: '', weight_percent: '', activities: [blankActivity(), blankActivity()] });
    const form = useForm({
        strategy_id: goal?.strategy_id ?? strategyId ?? '',
        description: goal?.description ?? '',
        objectives: goal && goal.objectives.length > 0
            ? goal.objectives.map((o) => ({
                key: newKey(), id: o.id, title: o.title, weight_percent: asText(o.weight_percent),
                activities: o.activities.map((a) => ({ key: newKey(), id: a.id, title: a.title, weight_percent: asText(a.weight_percent), time_minutes: a.time_minutes === null ? '' : String(a.time_minutes) })),
            }))
            : [blankObjective()],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const strategy = groups.flatMap((g) => g.strategies).find((s) => s.id === form.data.strategy_id) ?? null;
    const goalWeight = strategy?.weight_percent ?? goal?.weight_percent ?? '0';
    const objectivesTotal = form.data.objectives.reduce((sum, o) => sum + units(o.weight_percent), 0);
    const overview = route('performance.strategy.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });
    // Server checks that belong to no field on this form (plan locked, goal not a draft, code taken …).
    const otherErrors = Object.entries(errors).filter(([key, message]) => message && !RENDERED.test(key)).map(([, message]) => message as string);

    function setObjective(index: number, patch: Partial<ObjectiveInput>) {
        form.setData('objectives', form.data.objectives.map((o, i) => (i === index ? { ...o, ...patch } : o)));
    }

    function setActivity(objective: number, index: number, field: 'title' | 'weight_percent' | 'time_minutes', value: string) {
        const activities = form.data.objectives[objective].activities.map((a, i) => (i === index ? { ...a, [field]: value } : a));
        setObjective(objective, { activities });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...(goal ? {} : { strategy_id: data.strategy_id }),
            description: data.description || null,
            objectives: data.objectives.map((o) => ({
                id: o.id, title: o.title, weight_percent: o.weight_percent,
                activities: o.activities.map((a) => ({ id: a.id, title: a.title, weight_percent: a.weight_percent, time_minutes: a.time_minutes === '' ? null : Number(a.time_minutes) })),
            })),
        }));
        if (goal) form.put(route('performance.strategy.goals.update', goal.id));
        else form.post(route('performance.strategy.goals.store'));
    }

    const heading = goal ? `${t('performance.strategy.editGoal')}: ${goal.code}` : t('performance.strategy.registerGoal');

    return (
        <AuthenticatedLayout header={<PageHeader title={heading} description={`${nameOf(context.organization, locale)} · ${context.cycle?.code ?? ''} ${nameOf(context.cycle, locale)}`} backHref={overview} />}>
            <Head title={heading} />
            <form onSubmit={submit} className="min-w-0 space-y-6">
                {!editable && (
                    <div role="status" className="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                        {canPlan ? t('performance.strategy.locked') : t('performance.strategy.noPlanRight')}
                    </div>
                )}

                <Section title={t('performance.strategy.goal')} description={plan ? fill(t('performance.strategy.planNote'), { plan: plan.title }) : t('performance.strategy.planNew')}
                    actions={goal ? <Pill group="goal" value={goal.status} /> : undefined}>
                    <fieldset disabled={!editable} className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('performance.strategy.goal')} htmlFor="sg-strategy" error={errors.strategy_id} className="sm:col-span-2">
                            <select id="sg-strategy" className={inputCls} value={form.data.strategy_id} disabled={goal !== null} onChange={(e) => form.setData('strategy_id', e.target.value)} required>
                                <option value="">{t('performance.strategy.selectStrategy')}</option>
                                {groups.map((group) => (
                                    <optgroup key={group.id} label={`${group.number}. ${group.title} (${formatScore(group.weight_percent)}%)`}>
                                        {group.strategies.map((s) => (
                                            <option key={s.id} value={s.id} disabled={s.taken}>
                                                {s.number} {group.title} - {s.title} ({formatScore(s.weight_percent)}%){s.taken ? ` · ${t('performance.strategy.taken')}` : ''}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('performance.strategy.goalWeight')} htmlFor="sg-weight">
                            <input id="sg-weight" className={inputCls} value={`${formatScore(goalWeight)}%`} readOnly tabIndex={-1} />
                        </Field>
                        <Field label={t('performance.strategy.descriptionField')} htmlFor="sg-description" error={errors.description} className="sm:col-span-3">
                            <textarea id="sg-description" rows={2} className={textareaCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    </fieldset>
                </Section>

                {form.data.objectives.map((objective, oIndex) => {
                    const activitiesTotal = objective.activities.reduce((sum, a) => sum + units(a.weight_percent), 0);
                    const minutes = objective.activities.reduce((sum, a) => sum + (parseInt(a.time_minutes, 10) || 0), 0);
                    return (
                        <Section key={objective.key} flush title={`${t('performance.strategy.objective')} ${oIndex + 1}`}
                            actions={editable && form.data.objectives.length > 1
                                ? <button type="button" className={dangerLinkBtn} onClick={() => form.setData('objectives', form.data.objectives.filter((_, i) => i !== oIndex))}>{t('performance.strategy.removeObjective')}</button>
                                : undefined}>
                            <fieldset disabled={!editable}>
                                <div className="grid gap-4 px-5 pb-4 sm:grid-cols-4">
                                    <Field label={t('performance.strategy.objective')} htmlFor={`ob-${objective.key}`} error={errors[`objectives.${oIndex}.title`]} className="sm:col-span-3">
                                        <textarea id={`ob-${objective.key}`} rows={2} className={textareaCls} value={objective.title} maxLength={500} onChange={(e) => setObjective(oIndex, { title: e.target.value })} required />
                                    </Field>
                                    <Field label={t('performance.strategy.weight')} htmlFor={`ow-${objective.key}`} error={errors[`objectives.${oIndex}.weight_percent`]}>
                                        <input id={`ow-${objective.key}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={objective.weight_percent} onChange={(e) => setObjective(oIndex, { weight_percent: e.target.value })} required />
                                    </Field>
                                </div>
                                <Table head={<>
                                    <th className={`${thCls} w-16`}>{t('performance.strategy.serialNo')}</th>
                                    <th className={thCls}>{t('performance.strategy.activities')}</th>
                                    <th className={`${thCls} w-36`}>{t('performance.strategy.timeMinutes')}</th>
                                    <th className={`${thCls} w-36`}>{t('performance.strategy.weight')}</th>
                                    <th className={`${thCls} w-24`}><span className="sr-only">{t('performance.strategy.removeRow')}</span></th>
                                </>}>
                                    {objective.activities.map((activity, aIndex) => (
                                        <tr key={activity.key}>
                                            <td className={`${tdCls} tabular-nums`}>{oIndex + 1}.{aIndex + 1}</td>
                                            <td className={tdCls}>
                                                <input aria-label={`${t('performance.strategy.activity')} ${oIndex + 1}.${aIndex + 1}`} className={inputCls} value={activity.title} maxLength={500} onChange={(e) => setActivity(oIndex, aIndex, 'title', e.target.value)} required />
                                                <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.title`]} />
                                            </td>
                                            <td className={tdCls}>
                                                <input aria-label={`${t('performance.strategy.timeMinutes')} ${oIndex + 1}.${aIndex + 1}`} type="number" step="1" min="0" className={inputCls} value={activity.time_minutes} onChange={(e) => setActivity(oIndex, aIndex, 'time_minutes', e.target.value)} />
                                                <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.time_minutes`]} />
                                            </td>
                                            <td className={tdCls}>
                                                <input aria-label={`${t('performance.strategy.weight')} ${oIndex + 1}.${aIndex + 1}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={activity.weight_percent} onChange={(e) => setActivity(oIndex, aIndex, 'weight_percent', e.target.value)} required />
                                                <FieldError message={errors[`objectives.${oIndex}.activities.${aIndex}.weight_percent`]} />
                                            </td>
                                            <td className={`${tdCls} text-right`}>
                                                {editable && objective.activities.length > 2 && (
                                                    <button type="button" className={dangerLinkBtn} onClick={() => setObjective(oIndex, { activities: objective.activities.filter((_, i) => i !== aIndex) })}>{t('performance.strategy.removeRow')}</button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr>
                                        <td className={`${tdCls} font-semibold`} colSpan={2}>{t('performance.strategy.activitiesTotal')}</td>
                                        <td className={`${tdCls} tabular-nums text-(--app-muted-foreground)`}>{minutes}</td>
                                        <td className={`${tdCls} font-semibold tabular-nums ${activitiesTotal === 1000000 ? ok : off}`} colSpan={2}>{formatScore(activitiesTotal / 10000)}% / 100%</td>
                                    </tr>
                                </Table>
                                <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-4">
                                    <div>
                                        <FieldError message={errors[`objectives.${oIndex}.activities`]} />
                                        <p className="text-xs text-(--app-muted-foreground)">{t('performance.strategy.minActivities')}</p>
                                    </div>
                                    {editable && <button type="button" className={smallBtn} onClick={() => setObjective(oIndex, { activities: [...objective.activities, blankActivity()] })}><Plus className="h-4 w-4" aria-hidden="true" />{t('performance.strategy.addRow')}</button>}
                                </div>
                            </fieldset>
                        </Section>
                    );
                })}

                <section className="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <div>
                        <p className="text-sm font-semibold">
                            {t('performance.strategy.objectivesTotal')}:{' '}
                            <span className={`tabular-nums ${objectivesTotal === units(goalWeight) && objectivesTotal > 0 ? ok : off}`}>{formatScore(objectivesTotal / 10000)}% / {formatScore(goalWeight)}%</span>
                        </p>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('performance.strategy.goalHint')}</p>
                        <FieldError message={errors.objectives} />
                    </div>
                    {editable && <button type="button" className={smallBtn} onClick={() => form.setData('objectives', [...form.data.objectives, blankObjective()])}><Plus className="h-4 w-4" aria-hidden="true" />{t('performance.strategy.addObjective')}</button>}
                </section>

                <Problems title={t('performance.strategicGoals.readiness')} problems={otherErrors} />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-(--app-muted-foreground)">{t('performance.strategy.allocateNext')}</p>
                    <div className="flex flex-wrap gap-2">
                        <Link href={overview} className={secondaryBtn}>{editable ? t('performance.actions.cancel') : t('performance.strategy.back')}</Link>
                        {editable && <button type="submit" className={primaryBtn} disabled={form.processing}>{goal ? t('performance.actions.save') : t('performance.actions.create')}</button>}
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
