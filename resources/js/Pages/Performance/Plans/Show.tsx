import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Bar, Empty, Field, Pill, Problems, Section, Table, formatScore, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, smallBtn, tdCls, thCls, titleOf, useEnumLabel } from '@/Components/performance/ui';
import { ActualForm, AmendmentDiff, AmendmentForm, nullify } from '@/Components/performance/forms';
import type { PlanSummary } from '@/Pages/Performance/Plans/Index';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Target = {
    id: string; target_value: string | null; target_numerator: string | null; target_denominator: string | null; baseline_value: string | null;
    weight: string; achievement_cap: string | null; tolerance: string | null; zero_score_deviation: string | null; version_no: number; parent_target_id: string | null;
    period: [string, string];
    period_targets: { period_type: 'QUARTER' | 'MONTH'; period_number: number; target_value: string | null; target_numerator: string | null; target_denominator: string | null; is_cumulative: boolean }[];
    kpi: { id: string; code: string; name_en: string; name_am: string | null; direction: string; aggregation: string; source: string; unit: string | null };
};

type Objective = {
    id: string; code: string; title_en: string; title_am: string | null; description_en: string | null; description_am: string | null;
    weight: string; priority: number | null; is_mandatory: boolean; status: string; rejection_reason: string | null;
    strategic_goal_id: string | null; absolute_weight_percent: string | null; local_weight_percent: string | null;
    objective_type: string; cascade_mode: string; lineage: { code: string; title_en: string; plan: string | null } | null; targets: Target[];
};

type TraceTarget = { target_id: string; kpi_code: string; kpi_name_en: string; kpi_name_am?: string | null; target: string | null; actual: string | null; achievement: string | null; achievement_formula?: string | null; weight: string; weighted: string | null; health: string; lineage?: { source?: string } };
type PlanTrace = { formula?: Record<string, string>; score: string | null; objectives: { objective_id: string; code: string; title_en: string; title_am?: string | null; weight: string; score: string | null; contribution: string | null; targets: TraceTarget[] }[] };

type Props = {
    plan: PlanSummary & { change_reason: string | null; return_reason: string | null; parent: { id: string; title: string } | null; supersedes_plan_id: string | null; effective_from: string | null; effective_to: string | null; editable: boolean };
    objectives: Objective[];
    parentObjectives: { id: string; code: string; title_en: string; title_am: string | null; weight: string; is_mandatory: boolean; cascaded: boolean }[];
    childPlans: PlanSummary[];
    cascades: { parent: string | null; child_code: string | null; child_title_en: string | null; child_plan: string | null; type: string }[];
    versions: { id: string; version_no: number; status: string; published_at: string | null; change_reason: string | null }[];
    validation: string[];
    score: { as_of: string; score: string | null; trace: PlanTrace } | null;
    pendingAmendments: { id: string; subject_id: string; original_values: Record<string, unknown> | null; proposed_values: Record<string, unknown> | null; reason: string; effective_date: string; requested_by: number }[];
    kpis: { id: string; code: string; name_en: string; name_am: string | null; direction: string; unit_of_measure: string | null }[];
    parentTargets: { id: string; kpi_id: string; kpi_code: string }[];
    strategicGoals: { id: string; code: string; name_en: string; name_am: string | null; weight_percent: string }[];
    options: { objective_types: string[]; cascade_modes: string[] };
    can: { edit: boolean; submit: boolean; review: boolean; approve: boolean; publish: boolean; newVersion: boolean; enterActual: boolean; amend: boolean; decideAmendment: boolean; recalculate: boolean };
};


export default function PlanShow(props: Props) {
    const { plan, objectives, parentObjectives, childPlans, cascades, versions, validation, score, pendingAmendments, can } = props;
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [addingObjective, setAddingObjective] = useState(false);

    async function workflow(action: 'submit' | 'return' | 'approve' | 'publish') {
        const needsReason = action === 'return';
        const { confirmed, reason } = await confirm({
            title: t(`performance.actions.${action}`), description: plan.title, confirmLabel: t(`performance.actions.${action}`), cancelLabel: t('performance.actions.cancel'),
            requireReason: needsReason, reasonLabel: t('performance.fields.reason'), variant: needsReason ? 'danger' : undefined,
        });
        if (confirmed) router.post(route('performance.plans.workflow', [plan.id, action]), needsReason ? { reason } : {}, { preserveScroll: true });
    }

    async function newVersion() {
        const { confirmed, reason } = await confirm({ title: t('performance.actions.newVersion'), description: t('performance.plans.editLocked'), confirmLabel: t('performance.actions.newVersion'), cancelLabel: t('performance.actions.cancel'), requireReason: true, reasonLabel: t('performance.fields.reason') });
        if (confirmed) router.post(route('performance.plans.versions.store', plan.id), { reason });
    }

    const where = [nameOf(plan.organization, locale), nameOf(plan.unit, locale), titleOf(plan.position, locale)].filter(Boolean).join(' › ');

    return (
        <AuthenticatedLayout header={<PageHeader title={plan.title} description={`${label('planType', plan.type)} · ${where} · ${nameOf(plan.cycle, locale)}`} backHref={route('performance.plans.index')}
            actions={<div className="flex flex-wrap gap-2">
                {can.submit && <button type="button" className={primaryBtn} onClick={() => workflow('submit')}>{t('performance.actions.submit')}</button>}
                {(can.review || can.approve) && <button type="button" className={secondaryBtn} onClick={() => workflow('return')}>{t('performance.actions.return')}</button>}
                {can.approve && <button type="button" className={primaryBtn} onClick={() => workflow('approve')}>{t('performance.actions.approve')}</button>}
                {can.publish && <button type="button" className={primaryBtn} onClick={() => workflow('publish')}>{t('performance.actions.publish')}</button>}
                {can.newVersion && <button type="button" className={secondaryBtn} onClick={newVersion}>{t('performance.actions.newVersion')}</button>}
            </div>} />}>
            <Head title={plan.title} />
            <div className={pageCls}>
                <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-slate-400">
                    <Pill group="plan" value={plan.status} />
                    <span>{t('performance.fields.version')} {plan.version}</span>
                    {plan.parent && <span>{t('performance.fields.parentPlan')}: <Link className="text-[color:var(--color-primary)] hover:underline" href={route('performance.plans.show', plan.parent.id)}>{plan.parent.title}</Link></span>}
                    {!plan.parent && plan.type === 'ORGANIZATION' && <span>{t('performance.plans.noParent')}</span>}
                    {(plan.effective_from || plan.effective_to) && <span><LocalizedDateDisplay value={plan.effective_from} /> – <LocalizedDateDisplay value={plan.effective_to} /></span>}
                </div>
                {plan.return_reason && plan.status === 'DRAFT' && <Problems title={t('performance.enums.plan.DRAFT')} problems={[plan.return_reason]} />}
                {!plan.editable && plan.status === 'PUBLISHED' && <p className="text-xs text-gray-500 dark:text-slate-400">{t('performance.plans.editLocked')}</p>}
                <Problems title={t('performance.plans.validation')} problems={validation} />

                <Section title={t('performance.plans.objectives')} description={t('performance.plans.mandatoryNote')}
                    actions={can.edit && <button type="button" className={smallBtn} onClick={() => setAddingObjective((v) => !v)}>{t('performance.plans.addObjective')}</button>}>
                    {addingObjective && <ObjectiveForm planId={plan.id} types={props.options.objective_types} strategicGoals={props.strategicGoals} onDone={() => setAddingObjective(false)} />}
                    {objectives.length === 0 ? <Empty>{t('performance.dashboard.noData')}</Empty> : (
                        <div className="space-y-4">
                            {objectives.map((objective) => <ObjectiveCard key={objective.id} objective={objective} {...props} />)}
                        </div>
                    )}
                </Section>

                {props.parentObjectives.length > 0 && (
                    <Section title={t('performance.plans.cascadeFromParent')}>
                        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                            {parentObjectives.map((parent) => <CascadeRow key={parent.id} planId={plan.id} parent={parent} modes={props.options.cascade_modes} canEdit={can.edit} />)}
                        </ul>
                    </Section>
                )}

                {pendingAmendments.length > 0 && (
                    <Section title={t('performance.plans.amendments')}>
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                            {pendingAmendments.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <AmendmentDiff original={a.original_values} proposed={a.proposed_values} />
                                        <p className="text-xs text-gray-500">{a.reason} · <LocalizedDateDisplay value={a.effective_date} /></p>
                                    </div>
                                    {can.decideAmendment && (
                                        <div className="flex gap-2">
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.amendments.decide', a.id), { approve: false }, { preserveScroll: true })}>{t('performance.actions.decline')}</button>
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.amendments.decide', a.id), { approve: true }, { preserveScroll: true })}>{t('performance.actions.approve')}</button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section title={t('performance.plans.score')} description={score ? <>{t('performance.dashboard.asOf')} <LocalizedDateDisplay value={score.as_of} /></> : undefined}
                    actions={can.recalculate && <button type="button" className={smallBtn} onClick={() => router.post(route('performance.plans.recalculate', plan.id), {}, { preserveScroll: true })}>{t('performance.actions.recalculate')}</button>}>
                    {!score ? <Empty>{t('performance.notCalculated')}</Empty> : <PlanTraceView trace={score.trace} />}
                </Section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title={t('performance.plans.childPlans')}>
                        {childPlans.length === 0 ? <Empty>—</Empty> : (
                            <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                                {childPlans.map((child) => (
                                    <li key={child.id} className="flex items-center justify-between gap-2 py-2">
                                        <Link href={route('performance.plans.show', child.id)} className="text-[color:var(--color-primary)] hover:underline">{child.title} <span className="text-xs text-gray-500">· {nameOf(child.unit, locale) || titleOf(child.position, locale)}</span></Link>
                                        <Pill group="plan" value={child.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {cascades.length > 0 && (
                            <>
                                <h3 className="mt-4 text-xs font-semibold text-gray-700 dark:text-slate-300">{t('performance.plans.cascades')}</h3>
                                <ul className="mt-1 space-y-1 text-xs text-gray-600 dark:text-slate-400">
                                    {cascades.map((c, i) => <li key={i}>{c.parent} → {c.child_code} {c.child_title_en} · {c.child_plan} · {label('cascadeType', c.type)}</li>)}
                                </ul>
                            </>
                        )}
                    </Section>
                    <Section title={t('performance.plans.versions')}>
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-2 py-2">
                                    <div>
                                        {v.id === plan.id ? <span className="font-medium">v{v.version_no}</span> : <Link href={route('performance.plans.show', v.id)} className="text-[color:var(--color-primary)] hover:underline">v{v.version_no}</Link>}
                                        {v.change_reason && <span className="ml-2 text-xs text-gray-500">{v.change_reason}</span>}
                                    </div>
                                    <span className="flex items-center gap-2 text-xs"><LocalizedDateDisplay value={v.published_at} withTime fallback="" /><Pill group="plan" value={v.status} /></span>
                                </li>
                            ))}
                        </ul>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function ObjectiveCard({ objective, can, plan, kpis, parentTargets, options, strategicGoals }: Props & { objective: Objective }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [mode, setMode] = useState<'edit' | 'target' | null>(null);
    const [targetPanel, setTargetPanel] = useState<{ id: string; kind: 'actual' | 'amend' | 'edit' | 'periods' } | null>(null);
    const rejected = objective.status === 'REJECTED';

    async function remove() {
        const { confirmed } = await confirm({ title: t('performance.actions.remove'), description: `${objective.code} — ${titleOf(objective, locale)}`, confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.objectives.destroy', objective.id), { preserveScroll: true });
    }

    async function removeTarget(target: Target) {
        const { confirmed } = await confirm({ title: t('performance.actions.remove'), description: target.kpi.code, confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.targets.destroy', target.id), { preserveScroll: true });
    }

    return (
        <article className={`rounded-lg border p-3 ${rejected ? 'border-red-200 bg-red-50/40 dark:border-red-900 dark:bg-red-950/20' : 'border-gray-200 dark:border-slate-800'}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium text-gray-900 dark:text-slate-100">{objective.code} — {titleOf(objective, locale)}</p>
                    <p className="text-xs text-gray-500 dark:text-slate-400">
                        {label('objectiveType', objective.objective_type)} · {t('performance.fields.weight')} {formatScore(objective.weight)}
                        {objective.is_mandatory && ` · ${t('performance.fields.mandatory')}`}
                        {objective.lineage && ` · ${t('performance.plans.lineage')} ${objective.lineage.code} (${objective.lineage.plan ?? ''})`}
                    </p>
                    {rejected && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{t('performance.actions.decline')}: {objective.rejection_reason}</p>}
                </div>
                {can.edit && !rejected && (
                    <div className="flex gap-2">
                        <button type="button" className={smallBtn} onClick={() => setMode(mode === 'target' ? null : 'target')}>{t('performance.plans.addTarget')}</button>
                        <button type="button" className={smallBtn} onClick={() => setMode(mode === 'edit' ? null : 'edit')}>{t('performance.actions.edit')}</button>
                        {!objective.is_mandatory && <button type="button" className={smallBtn} onClick={remove}>{t('performance.actions.remove')}</button>}
                    </div>
                )}
            </div>

            {mode === 'edit' && <ObjectiveForm planId={plan.id} types={options.objective_types} strategicGoals={strategicGoals} objective={objective} onDone={() => setMode(null)} />}
            {mode === 'target' && <TargetForm objectiveId={objective.id} kpis={kpis} parentTargets={parentTargets} onDone={() => setMode(null)} />}

            {objective.targets.length > 0 && (
                <div className="mt-3">
                    <Table head={<>
                        <th className={thCls}>{t('performance.fields.kpi')}</th>
                        <th className={thCls}>{t('performance.fields.target')}</th>
                        <th className={thCls}>{t('performance.fields.period')}</th>
                        <th className={thCls}>{t('performance.fields.weight')}</th>
                        <th className={thCls}><span className="sr-only">{t('performance.actions.edit')}</span></th>
                    </>}>
                        {objective.targets.map((target) => (
                            <tr key={target.id}>
                                <td className={tdCls}>
                                    <p>{target.kpi.code} — {nameOf(target.kpi, locale)}</p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">{label('direction', target.kpi.direction)} · {label('aggregation', target.kpi.aggregation)} · {label('source', target.kpi.source)}{target.version_no > 1 ? ` · v${target.version_no}` : ''}</p>
                                    {targetPanel?.id === target.id && targetPanel.kind === 'actual' && <ActualForm url={route('performance.targets.actuals.store', target.id)} period={target.period} onDone={() => setTargetPanel(null)} />}
                                    {targetPanel?.id === target.id && targetPanel.kind === 'amend' && <AmendmentForm url={route('performance.targets.amendments.store', target.id)} onDone={() => setTargetPanel(null)} />}
                                    {targetPanel?.id === target.id && targetPanel.kind === 'edit' && <TargetForm target={target} kpis={kpis} parentTargets={parentTargets} onDone={() => setTargetPanel(null)} />}
                                    {targetPanel?.id === target.id && targetPanel.kind === 'periods' && <PeriodTargetsForm target={target} onDone={() => setTargetPanel(null)} />}
                                </td>
                                <td className={`${tdCls} tabular-nums`}>
                                    {target.target_numerator !== null ? `${formatScore(target.target_numerator)} / ${formatScore(target.target_denominator)}` : formatScore(target.target_value)} {target.kpi.unit ?? ''}
                                    {target.baseline_value !== null && <span className="block text-xs text-gray-500">{t('performance.fields.baseline')}: {formatScore(target.baseline_value)}</span>}
                                </td>
                                <td className={`${tdCls} whitespace-nowrap text-xs`}><LocalizedDateDisplay value={target.period[0]} /> – <LocalizedDateDisplay value={target.period[1]} /></td>
                                <td className={`${tdCls} tabular-nums`}>{formatScore(target.weight)}</td>
                                <td className={`${tdCls} whitespace-nowrap`}>
                                    <div className="flex flex-wrap gap-1">
                                        {can.edit && <button type="button" className={smallBtn} onClick={() => setTargetPanel({ id: target.id, kind: 'edit' })}>{t('performance.actions.edit')}</button>}
                                        {can.edit && <button type="button" className={smallBtn} onClick={() => setTargetPanel({ id: target.id, kind: 'periods' })}>{t('performance.plans.distributeTargets')}</button>}
                                        {can.edit && <button type="button" className={smallBtn} onClick={() => removeTarget(target)}>{t('performance.actions.remove')}</button>}
                                        {can.enterActual && <button type="button" className={smallBtn} onClick={() => setTargetPanel({ id: target.id, kind: 'actual' })}>{t('performance.plans.enterActual')}</button>}
                                        {can.amend && <button type="button" className={smallBtn} onClick={() => setTargetPanel({ id: target.id, kind: 'amend' })}>{t('performance.actions.requestAmendment')}</button>}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </div>
            )}
        </article>
    );
}

function PeriodTargetsForm({ target, onDone }: { target: Target; onDone: () => void }) {
    const { t } = useLocale();
    const initialType = target.period_targets[0]?.period_type ?? 'QUARTER';
    const [periodType, setPeriodType] = useState<'QUARTER' | 'MONTH'>(initialType);
    const makeRows = (type: 'QUARTER' | 'MONTH') => Array.from({ length: type === 'QUARTER' ? 4 : 12 }, (_, index) => {
        const row = target.period_targets.find((item) => item.period_type === type && item.period_number === index + 1);
        return { period_type: type, period_number: index + 1, target_value: row?.target_value ?? '', target_numerator: row?.target_numerator ?? '', target_denominator: row?.target_denominator ?? '', is_cumulative: row?.is_cumulative ?? false };
    });
    const form = useForm({ period_targets: makeRows(initialType) });
    function switchType(value: 'QUARTER' | 'MONTH') {
        setPeriodType(value);
        form.setData('period_targets', makeRows(value));
    }
    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ period_targets: data.period_targets.map((row) => nullify(row)) }));
        form.put(route('performance.targets.period-targets.replace', target.id), { preserveScroll: true, onSuccess: onDone });
    }
    return <form onSubmit={submit} className="mt-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
        <div className="flex flex-wrap items-center justify-between gap-2"><p className="text-xs font-semibold">{t('performance.plans.periodTargets')}</p><select className={`${inputCls} w-auto`} value={periodType} onChange={(e) => switchType(e.target.value as 'QUARTER' | 'MONTH')}><option value="QUARTER">{t('performance.plans.quarterly')}</option><option value="MONTH">{t('performance.plans.monthly')}</option></select></div>
        <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{form.data.period_targets.map((row, index) => <Field key={row.period_number} label={`${periodType === 'QUARTER' ? t('performance.plans.quarterShort') : t('performance.plans.monthShort')}${row.period_number}`} error={(form.errors as Record<string, string>)[`period_targets.${index}.target_value`]}><input className={inputCls} inputMode="decimal" value={row.target_value} onChange={(e) => form.setData('period_targets', form.data.period_targets.map((item, i) => i === index ? { ...item, target_value: e.target.value } : item))} required /></Field>)}</div>
        <p className="mt-2 text-xs text-gray-500">{t('performance.plans.periodTargetsHelp')}</p>
        <div className="mt-3 flex justify-end gap-2"><button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button><button className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button></div>
    </form>;
}

function ObjectiveForm({ planId, types, strategicGoals, objective, onDone }: { planId: string; types: string[]; strategicGoals: Props['strategicGoals']; objective?: Objective; onDone: () => void }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const form = useForm({
        strategic_goal_id: objective?.strategic_goal_id ?? '', code: objective?.code ?? '', title_en: objective?.title_en ?? '', title_am: objective?.title_am ?? '', description_en: objective?.description_en ?? '',
        objective_type: objective?.objective_type ?? 'ANNUAL', is_mandatory: objective?.is_mandatory ?? false, weight: objective?.weight ?? '', priority: objective?.priority?.toString() ?? '',
        absolute_weight_percent: objective?.absolute_weight_percent ?? '', local_weight_percent: objective?.local_weight_percent ?? '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform(nullify);
        const options = { preserveScroll: true, onSuccess: onDone };
        if (objective) form.put(route('performance.objectives.update', objective.id), options);
        else form.post(route('performance.plans.objectives.store', planId), options);
    }

    return (
        <form onSubmit={submit} className="my-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-4 dark:bg-slate-800/50">
            {strategicGoals.length > 0 && <Field label={t('performance.strategicGoals.title')} error={form.errors.strategic_goal_id} className="sm:col-span-2"><select className={inputCls} value={form.data.strategic_goal_id} onChange={(e) => form.setData('strategic_goal_id', e.target.value)}><option value="">—</option>{strategicGoals.map((goal) => <option key={goal.id} value={goal.id}>{goal.code} — {(locale === 'am' && goal.name_am) || goal.name_en} ({formatScore(goal.weight_percent)}%)</option>)}</select></Field>}
            <Field label={t('performance.fields.code')} error={form.errors.code}><input className={inputCls} value={form.data.code} disabled={!!objective} onChange={(e) => form.setData('code', e.target.value)} required={!objective} /></Field>
            <Field label={t('performance.fields.titleEn')} error={form.errors.title_en} className="lg:col-span-2"><input className={inputCls} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} required /></Field>
            <Field label={t('performance.fields.weight')} error={form.errors.weight}><input className={inputCls} inputMode="decimal" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} required /></Field>
            {strategicGoals.length > 0 && <Field label={t('performance.fields.absoluteWeight')} error={form.errors.absolute_weight_percent}><input className={inputCls} inputMode="decimal" value={form.data.absolute_weight_percent} onChange={(e) => form.setData('absolute_weight_percent', e.target.value)} /></Field>}
            <Field label={t('performance.fields.titleAm')} error={form.errors.title_am} className="lg:col-span-2"><input className={inputCls} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} /></Field>
            <Field label={t('performance.fields.type')} error={form.errors.objective_type}>
                <select className={inputCls} value={form.data.objective_type} onChange={(e) => form.setData('objective_type', e.target.value)}>
                    {types.map((v) => <option key={v} value={v}>{label('objectiveType', v)}</option>)}
                </select>
            </Field>
            <label className="flex min-h-10 items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.data.is_mandatory} onChange={(e) => form.setData('is_mandatory', e.target.checked)} />{t('performance.fields.mandatory')}</label>
            <Field label={t('performance.fields.description')} error={form.errors.description_en} className="sm:col-span-2 lg:col-span-4"><textarea rows={2} className={inputCls} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} /></Field>
            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

function TargetForm({ objectiveId, target, kpis, parentTargets, onDone }: { objectiveId?: string; target?: Target; kpis: Props['kpis']; parentTargets: Props['parentTargets']; onDone: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({
        kpi_id: target?.kpi.id ?? '', parent_target_id: target?.parent_target_id ?? '', period_start: target?.period[0] ?? '', period_end: target?.period[1] ?? '',
        baseline_value: target?.baseline_value ?? '', target_value: target?.target_value ?? '', target_numerator: target?.target_numerator ?? '', target_denominator: target?.target_denominator ?? '',
        weight: target?.weight ?? '', achievement_cap: target?.achievement_cap ?? '', tolerance: target?.tolerance ?? '', zero_score_deviation: target?.zero_score_deviation ?? '',
    });
    const kpiId = form.data.kpi_id;
    const direction = target?.kpi.direction ?? kpis.find((k) => k.id === kpiId)?.direction;
    const parents = parentTargets.filter((p) => p.kpi_id === kpiId);

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => {
            const payload = nullify(data);
            if (target) delete payload.kpi_id;
            return payload;
        });
        const options = { preserveScroll: true, onSuccess: onDone };
        if (target) form.put(route('performance.targets.update', target.id), options);
        else form.post(route('performance.objectives.targets.store', objectiveId), options);
    }

    return (
        <form onSubmit={submit} className="my-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-4 dark:bg-slate-800/50">
            {!target && (
                <Field label={t('performance.fields.kpi')} error={form.errors.kpi_id} className="sm:col-span-2">
                    <select className={inputCls} value={form.data.kpi_id} onChange={(e) => form.setData({ ...form.data, kpi_id: e.target.value, parent_target_id: '' })} required>
                        <option value="">—</option>
                        {kpis.map((k) => <option key={k.id} value={k.id}>{k.code} — {nameOf(k, locale)}</option>)}
                    </select>
                </Field>
            )}
            {parents.length > 0 && (
                <Field label={t('performance.fields.parentTarget')} error={form.errors.parent_target_id}>
                    <select className={inputCls} value={form.data.parent_target_id} onChange={(e) => form.setData('parent_target_id', e.target.value)}>
                        <option value="">—</option>
                        {parents.map((p) => <option key={p.id} value={p.id}>{p.kpi_code}</option>)}
                    </select>
                </Field>
            )}
            <Field label={t('performance.fields.weight')} error={form.errors.weight}><input className={inputCls} inputMode="decimal" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} required /></Field>
            <Field label={t('performance.fields.target')} error={form.errors.target_value}><input className={inputCls} inputMode="decimal" value={form.data.target_value} onChange={(e) => form.setData('target_value', e.target.value)} /></Field>
            <Field label={t('performance.fields.numerator')} error={form.errors.target_numerator}><input className={inputCls} inputMode="decimal" value={form.data.target_numerator} onChange={(e) => form.setData('target_numerator', e.target.value)} /></Field>
            <Field label={t('performance.fields.denominator')} error={form.errors.target_denominator}><input className={inputCls} inputMode="decimal" value={form.data.target_denominator} onChange={(e) => form.setData('target_denominator', e.target.value)} /></Field>
            <Field label={t('performance.fields.baseline')} error={form.errors.baseline_value}><input className={inputCls} inputMode="decimal" value={form.data.baseline_value} onChange={(e) => form.setData('baseline_value', e.target.value)} /></Field>
            <Field label={`${t('performance.fields.period')} — ${t('performance.fields.from')}`} error={form.errors.period_start}><LocalizedDatePicker value={form.data.period_start} onChange={(v) => form.setData('period_start', v)} /></Field>
            <Field label={`${t('performance.fields.period')} — ${t('performance.fields.to')}`} error={form.errors.period_end}><LocalizedDatePicker value={form.data.period_end} onChange={(v) => form.setData('period_end', v)} /></Field>
            <Field label={t('performance.fields.cap')} error={form.errors.achievement_cap}><input className={inputCls} inputMode="decimal" value={form.data.achievement_cap} onChange={(e) => form.setData('achievement_cap', e.target.value)} /></Field>
            {direction === 'TARGET_IS_BEST' && <Field label={t('performance.fields.tolerance')} error={form.errors.tolerance}><input className={inputCls} inputMode="decimal" value={form.data.tolerance} onChange={(e) => form.setData('tolerance', e.target.value)} /></Field>}
            {direction === 'TARGET_IS_BEST' && <Field label={t('performance.fields.zeroScore')} error={form.errors.zero_score_deviation}><input className={inputCls} inputMode="decimal" value={form.data.zero_score_deviation} onChange={(e) => form.setData('zero_score_deviation', e.target.value)} /></Field>}
            <div className="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

function CascadeRow({ planId, parent, modes, canEdit }: { planId: string; parent: Props['parentObjectives'][number]; modes: string[]; canEdit: boolean }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [open, setOpen] = useState(false);
    const form = useForm({ parent_objective_id: parent.id, mode: 'ACCEPT', title_en: '', title_am: '', weight: '', copy_targets: true, parts: [] as { title_en: string; weight: string }[] });
    const errors = form.errors as Record<string, string | undefined>;

    async function decline() {
        const { confirmed, reason } = await confirm({ title: t('performance.actions.decline'), description: `${parent.code} — ${titleOf(parent, locale)}`, confirmLabel: t('performance.actions.decline'), cancelLabel: t('performance.actions.cancel'), requireReason: true, reasonLabel: t('performance.fields.reason'), variant: 'danger' });
        if (confirmed) router.post(route('performance.plans.decline', planId), { parent_objective_id: parent.id, reason }, { preserveScroll: true });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...nullify({ ...data, parts: undefined }), copy_targets: data.copy_targets, parts: data.mode === 'SPLIT' ? data.parts : null }));
        form.post(route('performance.plans.cascade', planId), { preserveScroll: true, onSuccess: () => setOpen(false) });
    }

    return (
        <li className="py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0 text-sm">
                    <p className="font-medium">{parent.code} — {titleOf(parent, locale)}</p>
                    <p className="text-xs text-gray-500">{t('performance.fields.weight')} {formatScore(parent.weight)}{parent.is_mandatory && ` · ${t('performance.fields.mandatory')}`}</p>
                </div>
                {parent.cascaded ? <span className="text-xs text-emerald-700 dark:text-emerald-400">{t('performance.plans.alreadyCascaded')}</span> : canEdit && (
                    <div className="flex gap-2">
                        <button type="button" className={smallBtn} onClick={() => setOpen((v) => !v)}>{t('performance.actions.cascade')}</button>
                        {!parent.is_mandatory && <button type="button" className={smallBtn} onClick={decline}>{t('performance.actions.decline')}</button>}
                    </div>
                )}
            </div>
            {open && (
                <form onSubmit={submit} className="mt-2 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-3 dark:bg-slate-800/50">
                    <Field label={t('performance.fields.mode')} error={errors.mode}>
                        <select className={inputCls} value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                            {modes.map((m) => <option key={m} value={m}>{label('cascade', m)}</option>)}
                        </select>
                    </Field>
                    {form.data.mode !== 'ACCEPT' && form.data.mode !== 'SPLIT' && <Field label={t('performance.fields.titleEn')} error={errors.title_en}><input className={inputCls} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} /></Field>}
                    {form.data.mode !== 'SPLIT' && <Field label={t('performance.fields.weight')} error={errors.weight}><input className={inputCls} inputMode="decimal" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} /></Field>}
                    {form.data.mode === 'SPLIT' && (
                        <div className="space-y-2 sm:col-span-3">
                            <p className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('performance.fields.parts')}</p>
                            {form.data.parts.map((part, i) => (
                                <div key={i} className="grid grid-cols-[1fr_6rem_auto] gap-2">
                                    <input aria-label={t('performance.fields.titleEn')} className={inputCls} value={part.title_en} onChange={(e) => form.setData('parts', form.data.parts.map((p, j) => j === i ? { ...p, title_en: e.target.value } : p))} required />
                                    <input aria-label={t('performance.fields.weight')} className={inputCls} inputMode="decimal" value={part.weight} onChange={(e) => form.setData('parts', form.data.parts.map((p, j) => j === i ? { ...p, weight: e.target.value } : p))} required />
                                    <button type="button" className={smallBtn} onClick={() => form.setData('parts', form.data.parts.filter((_, j) => j !== i))}>{t('performance.actions.remove')}</button>
                                </div>
                            ))}
                            <button type="button" className={smallBtn} onClick={() => form.setData('parts', [...form.data.parts, { title_en: '', weight: '' }])}>{t('performance.actions.add')}</button>
                            {errors.parts && <p className="text-xs text-red-700">{errors.parts}</p>}
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.data.copy_targets} onChange={(e) => form.setData('copy_targets', e.target.checked)} />{t('performance.fields.copyTargets')}</label>
                    <div className="flex justify-end gap-2">
                        <button type="button" className={smallBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                        <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.cascade')}</button>
                    </div>
                    {errors.parent_objective_id && <p className="text-xs text-red-700 sm:col-span-3">{errors.parent_objective_id}</p>}
                </form>
            )}
        </li>
    );
}

function PlanTraceView({ trace }: { trace: PlanTrace }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    return (
        <div className="space-y-4">
            <p className="text-2xl font-semibold tabular-nums text-gray-900 dark:text-slate-100">{trace.score === null ? <span className="text-sm font-normal text-gray-500">{t('performance.notCalculated')}</span> : `${formatScore(trace.score)}%`}</p>
            {trace.objectives.map((objective) => (
                <div key={objective.objective_id}>
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span className="font-medium">{objective.code} — {(locale === 'am' && objective.title_am) || objective.title_en}</span>
                        <span className="tabular-nums">{formatScore(objective.score)}% × {formatScore(objective.weight)} = {formatScore(objective.contribution)}</span>
                    </div>
                    <Table head={<>
                        <th className={thCls}>{t('performance.fields.kpi')}</th>
                        <th className={thCls}>{t('performance.fields.target')}</th>
                        <th className={thCls}>{t('performance.fields.actual')}</th>
                        <th className={thCls}>{t('performance.fields.achievement')}</th>
                        <th className={thCls}>{t('performance.fields.status')}</th>
                    </>}>
                        {objective.targets.map((row) => (
                            <tr key={row.target_id}>
                                <td className={tdCls}>{row.kpi_code} — {(locale === 'am' && row.kpi_name_am) || row.kpi_name_en}{row.achievement_formula && <p className="font-mono text-[11px] text-gray-500">{row.achievement_formula}</p>}</td>
                                <td className={`${tdCls} tabular-nums`}>{formatScore(row.target)}</td>
                                <td className={`${tdCls} tabular-nums`}>{formatScore(row.actual)}</td>
                                <td className={`${tdCls} w-40`}><span className="tabular-nums">{row.achievement === null ? '—' : `${formatScore(row.achievement)}%`}</span><Bar value={row.achievement} /></td>
                                <td className={tdCls}><Pill group="health" value={row.health} />{row.lineage?.source === 'contributors' && <span className="block text-[11px] text-gray-500">{label('source', 'FORMULA')}</span>}</td>
                            </tr>
                        ))}
                    </Table>
                </div>
            ))}
            {trace.formula && (
                <details className="text-xs text-gray-600 dark:text-slate-400">
                    <summary className="cursor-pointer font-medium">{t('performance.plans.scoreTrace')}</summary>
                    <ul className="mt-1 space-y-0.5 font-mono">{Object.entries(trace.formula).map(([k, v]) => <li key={k}>{k}: {v}</li>)}</ul>
                </details>
            )}
        </div>
    );
}
