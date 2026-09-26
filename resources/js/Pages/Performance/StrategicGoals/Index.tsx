import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Bar, Field, Pager, Pill, Problems, Section, Stat, dangerLinkBtn, fill, formatScore, inputCls, linkBtn, nameOf, pageCls, primaryBtn, secondaryBtn, smallPrimaryBtn, useEnumLabel, type Paginator } from '@/Components/performance/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Named = { id: string; name_en: string; name_am?: string | null };
type Allocation = { id: string; organization_unit_id: string; organization_contribution_percent: string; allocation_type: string; is_lead: boolean; notes?: string | null; unit?: Named | null };
type LinkedObjective = {
    id: string; code: string; title_en: string; title_am: string | null; weight: string; absolute_weight_percent: string | null;
    plan: { id: string; title: string; status: string; version: number } | null;
};
type Goal = {
    id: string; cycle_id: string; organization_id: string; code: string; name_en: string; name_am: string; description_en?: string | null; description_am?: string | null;
    weight_percent: string; is_shared: boolean; sort_order: number; status: string; return_reason?: string | null; effective_from: string | null; effective_to: string | null;
    objectives_count: number; objectives: LinkedObjective[]; allocations: Allocation[];
    readiness: { allocation_total: string; objective_total: string; remaining: string; ready: boolean; problems: string[] };
};
type Cycle = Named & { code: string; organization_id: string | null; status: string; is_current: boolean; start_date: string | null; end_date: string | null };
type Summary = { total: string; remaining: string; ready: boolean; problems: string[]; status_counts: Record<string, number> };
type Props = {
    goals: Paginator<Goal>; filters: { cycle_id: string; organization_id: string };
    cycles: Cycle[]; organizations: Named[]; units: (Named & { organization_id: string })[];
    summary: Summary | null; allocationTypes: string[];
    can: { create: boolean; update: boolean; delete: boolean; allocate: boolean; approve: boolean; publish: boolean };
};

const GOAL_STATUSES = ['DRAFT', 'UNDER_REVIEW', 'APPROVED', 'PUBLISHED', 'SUPERSEDED'];

/** Share of `part` in `whole` as a percentage for the progress bars (null when the whole is zero). */
const share = (part: string | number, whole: string | number): number | null => {
    const w = Number(whole);
    return w > 0 ? (Number(part) / w) * 100 : null;
};

/** Add a unit to a goal, or change an allocation (the unit itself stays). */
function AllocationForm({ goal, allocation, units, types, onClose }: { goal: Goal; allocation?: Allocation; units: Props['units']; types: string[]; onClose: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({
        organization_unit_id: allocation?.organization_unit_id ?? '',
        organization_contribution_percent: allocation?.organization_contribution_percent ?? '',
        allocation_type: allocation?.allocation_type ?? 'PRIMARY',
        is_lead: allocation?.is_lead ?? goal.allocations.length === 0,
        notes: allocation?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const available = units.filter((unit) => unit.organization_id === goal.organization_id && !goal.allocations.some((row) => row.organization_unit_id === unit.id));
    const unallocated = Number(goal.weight_percent) - Number(goal.readiness.allocation_total) + (allocation ? Number(allocation.organization_contribution_percent) : 0);
    const id = (field: string) => `sg-${goal.id}-${allocation?.id ?? 'new'}-${field}`;

    function submit(e: FormEvent) {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: onClose };
        if (allocation) {
            form.transform(({ organization_unit_id: _unit, ...data }) => data);
            form.put(route('performance.strategic-goal-allocations.update', allocation.id), done);
        } else {
            form.transform((data) => data);
            form.post(route('performance.strategic-goals.allocations.store', goal.id), done);
        }
    }

    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-950/50 sm:grid-cols-2 lg:grid-cols-4">
            <p className="text-xs font-semibold text-gray-700 dark:text-slate-200 sm:col-span-2 lg:col-span-4">{allocation ? t('performance.strategicGoals.editAllocation') : t('performance.strategicGoals.addAllocation')}</p>
            <Field label={t('performance.fields.unit')} htmlFor={id('unit')} error={errors.organization_unit_id}>
                {allocation ? (
                    <p id={id('unit')} className="py-2 text-sm font-medium text-gray-900 dark:text-slate-100">{nameOf(allocation.unit, locale)}</p>
                ) : (
                    <select id={id('unit')} className={inputCls} value={form.data.organization_unit_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)} required>
                        <option value="">{t('performance.strategicGoals.selectUnit')}</option>
                        {available.map((u) => <option key={u.id} value={u.id}>{nameOf(u, locale)}</option>)}
                    </select>
                )}
            </Field>
            {!allocation && available.length === 0 && <p role="status" className="text-sm text-amber-700 dark:text-amber-300 sm:col-span-2 lg:col-span-4">{t('performance.strategicGoals.noAvailableUnits')}</p>}
            <Field label={t('performance.fields.contribution')} htmlFor={id('share')} error={errors.organization_contribution_percent}
                help={fill(t('performance.strategicGoals.allocationRemainingHint'), { value: formatScore(Math.max(0, unallocated)) })}>
                <input id={id('share')} className={inputCls} type="number" inputMode="decimal" min="0.0001" max="100" step="0.0001" value={form.data.organization_contribution_percent}
                    onChange={(e) => form.setData('organization_contribution_percent', e.target.value)} required />
            </Field>
            <Field label={t('performance.fields.allocationType')} htmlFor={id('type')} error={errors.allocation_type}>
                <select id={id('type')} className={inputCls} value={form.data.allocation_type} onChange={(e) => form.setData('allocation_type', e.target.value)}>
                    {types.map((type) => <option key={type} value={type}>{t(`performance.enums.allocation.${type}`)}</option>)}
                </select>
            </Field>
            <div>
                <label className="flex min-h-10 items-center gap-2 pt-6 text-sm text-gray-700 dark:text-slate-200">
                    <input type="checkbox" checked={form.data.is_lead} onChange={(e) => form.setData('is_lead', e.target.checked)} />{t('performance.fields.leadUnit')}
                </label>
                {errors.is_lead && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.is_lead}</p>}
            </div>
            <Field label={t('performance.fields.notes')} htmlFor={id('notes')} error={errors.notes} className="sm:col-span-2 lg:col-span-4">
                <textarea id={id('notes')} rows={2} maxLength={2000} className={inputCls} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </Field>
            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" className={secondaryBtn} onClick={onClose}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallPrimaryBtn} disabled={form.processing || (!allocation && available.length === 0)}>{allocation ? t('performance.actions.save') : t('performance.actions.add')}</button>
            </div>
        </form>
    );
}

export default function StrategicGoalsIndex({ goals, filters, cycles, organizations, units, summary, allocationTypes, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Goal | 'new' | null>(null);
    const [allocating, setAllocating] = useState<{ goal: string; allocation: string | null } | null>(null);
    const blank = { cycle_id: filters.cycle_id, organization_id: filters.organization_id, code: '', name_en: '', name_am: '', description_en: '', description_am: '', weight_percent: '', is_shared: false, sort_order: 0, effective_from: '', effective_to: '' };
    const form = useForm(blank);
    const errors = form.errors as Record<string, string | undefined>;
    const existing = editing !== null && editing !== 'new' ? editing : null;
    const cycle = cycles.find((c) => c.id === filters.cycle_id) ?? null;
    // Only the chosen organization's cycles (and city-wide ones) can be combined with it.
    const cycleOptions = cycles.filter((c) => !filters.organization_id || c.organization_id === null || c.organization_id === filters.organization_id);
    const unregistered = summary ? Number(summary.remaining) + (existing ? Number(existing.weight_percent) : 0) : null;

    function filter(key: 'cycle_id' | 'organization_id', value: string) {
        const next = { ...filters, [key]: value };
        // A cycle of another organization cannot stay selected.
        if (key === 'organization_id' && next.cycle_id) {
            const chosen = cycles.find((c) => c.id === next.cycle_id);
            if (chosen?.organization_id && chosen.organization_id !== value) next.cycle_id = '';
        }
        router.get(route('performance.strategic-goals.index'), next, {
            preserveState: true, replace: true,
            onSuccess: () => { setEditing(null); setAllocating(null); form.clearErrors(); },
        });
    }

    function start(goal: Goal | 'new') {
        form.clearErrors();
        form.setData(goal === 'new' ? blank : {
            cycle_id: goal.cycle_id, organization_id: goal.organization_id, code: goal.code, name_en: goal.name_en, name_am: goal.name_am,
            description_en: goal.description_en ?? '', description_am: goal.description_am ?? '', weight_percent: goal.weight_percent, is_shared: goal.is_shared,
            sort_order: goal.sort_order, effective_from: goal.effective_from ?? '', effective_to: goal.effective_to ?? '',
        });
        setEditing(goal);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (existing) {
            form.transform(({ cycle_id: _cycle, organization_id: _organization, ...data }) => data);
            form.put(route('performance.strategic-goals.update', existing.id), done);
        } else {
            form.transform((data) => data);
            form.post(route('performance.strategic-goals.store'), done);
        }
    }

    const goalName = (goal: Goal) => `${goal.code} — ${(locale === 'am' && goal.name_am) || goal.name_en}`;

    async function transition(goal: Goal, status: 'UNDER_REVIEW' | 'APPROVED' | 'PUBLISHED') {
        const action = { UNDER_REVIEW: 'submit', APPROVED: 'approve', PUBLISHED: 'publish' }[status];
        const { confirmed } = await confirm({ title: t(`performance.actions.${action}`), description: goalName(goal), confirmLabel: t(`performance.actions.${action}`), cancelLabel: t('performance.actions.cancel') });
        if (confirmed) router.post(route('performance.strategic-goals.transition', goal.id), { status }, { preserveScroll: true });
    }

    async function returnGoal(goal: Goal) {
        const { confirmed, reason } = await confirm({ title: t('performance.actions.return'), description: goalName(goal), confirmLabel: t('performance.actions.return'), cancelLabel: t('performance.actions.cancel'), requireReason: true, reasonLabel: t('performance.fields.reason'), variant: 'danger' });
        if (confirmed) router.post(route('performance.strategic-goals.return', goal.id), { reason }, { preserveScroll: true });
    }

    async function removeGoal(goal: Goal) {
        const { confirmed } = await confirm({ title: t('performance.actions.remove'), description: goalName(goal), confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategic-goals.destroy', goal.id), { preserveScroll: true });
    }

    async function removeAllocation(goal: Goal, row: Allocation) {
        const { confirmed } = await confirm({ title: t('performance.strategicGoals.removeAllocation'), description: `${goalName(goal)} · ${nameOf(row.unit, locale)}`, confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategic-goal-allocations.destroy', row.id), { preserveScroll: true });
    }

    const contextSelected = Boolean(filters.cycle_id && filters.organization_id);
    const statusCounts = summary ? GOAL_STATUSES.filter((status) => (summary.status_counts[status] ?? 0) > 0) : [];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.strategicGoals.title')} description={t('performance.strategicGoals.description')}
            actions={can.create && contextSelected ? <button type="button" className={primaryBtn} onClick={() => (editing === 'new' ? setEditing(null) : start('new'))}>{t('performance.strategicGoals.create')}</button> : undefined} />}>
            <Head title={t('performance.strategicGoals.title')} />
            <div className={pageCls}>
                <section className="rounded-panel border border-blue-100 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30" aria-label={t('performance.strategicGoals.workflow')}>
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-white">{t('performance.strategicGoals.workflow')}</h2>
                    <ol className="mt-3 grid gap-3 text-sm text-gray-600 dark:text-slate-300 sm:grid-cols-3">
                        {['registerStep', 'allocateStep', 'reviewStep'].map((step, index) => (
                            <li key={step} className="flex items-start gap-2"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-semibold text-blue-700 dark:bg-slate-900 dark:text-blue-300">{index + 1}</span><span>{t(`performance.strategicGoals.${step}`)}</span></li>
                        ))}
                    </ol>
                </section>
                <section className="grid gap-4 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2" aria-label={t('performance.strategicGoals.selectContext')}>
                    <Field label={t('performance.fields.organization')} htmlFor="sg-organization">
                        <select id="sg-organization" className={inputCls} value={filters.organization_id} onChange={(e) => filter('organization_id', e.target.value)}>
                            <option value="">{t('performance.strategicGoals.selectOrganization')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('performance.fields.cycle')} htmlFor="sg-cycle">
                        <select id="sg-cycle" className={inputCls} value={filters.cycle_id} onChange={(e) => filter('cycle_id', e.target.value)}>
                            <option value="">{t('performance.strategicGoals.selectCycle')}</option>
                            {cycleOptions.map((c) => (
                                <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)} ({label('cycle', c.status)}{c.is_current ? ` · ${t('performance.cycles.current')}` : ''})</option>
                            ))}
                        </select>
                    </Field>
                    {cycle && (
                        <p className="text-xs text-gray-500 dark:text-slate-400 sm:col-span-2">
                            {t('performance.fields.period')}: <LocalizedDateDisplay value={cycle.start_date} /> – <LocalizedDateDisplay value={cycle.end_date} />
                        </p>
                    )}
                </section>

                {summary && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Stat label={t('performance.strategicGoals.total')} value={summary.total} suffix="%" hint={<div className="mt-2"><Bar value={summary.total} /></div>} />
                            <Stat label={t('performance.strategicGoals.remaining')} value={summary.remaining} suffix="%" />
                            <Stat label={t('performance.strategicGoals.readiness')} value={summary.ready ? t('performance.strategicGoals.ready') : t('performance.strategicGoals.needsWork')} />
                        </div>
                        {statusCounts.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
                                <span>{t('performance.strategicGoals.byStatus')}:</span>
                                {statusCounts.map((status) => (
                                    <span key={status} className="inline-flex items-center gap-1.5"><Pill group="goal" value={status} /><span className="tabular-nums">{summary.status_counts[status]}</span></span>
                                ))}
                            </div>
                        )}
                        <Problems title={t('performance.strategicGoals.readiness')} problems={summary.problems} />
                    </>
                )}

                {editing && (
                    <Section title={existing ? `${t('performance.strategicGoals.editGoal')}: ${existing.code}` : t('performance.strategicGoals.create')}>
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label={t('performance.fields.code')} htmlFor="sg-code" error={errors.code} help={t('performance.strategicGoals.codeHelp')}>
                                <input id="sg-code" className={inputCls} value={form.data.code} maxLength={50} onChange={(e) => form.setData('code', e.target.value)} required />
                            </Field>
                            <Field label={t('performance.fields.weight')} htmlFor="sg-weight" error={errors.weight_percent}
                                help={unregistered === null ? undefined : fill(t('performance.strategicGoals.remainingHint'), { value: formatScore(Math.max(0, unregistered)) })}>
                                <input id="sg-weight" className={inputCls} type="number" inputMode="decimal" min="0.0001" max="100" step="0.0001" value={form.data.weight_percent} onChange={(e) => form.setData('weight_percent', e.target.value)} required />
                            </Field>
                            <Field label={t('performance.fields.sortOrder')} htmlFor="sg-order" error={errors.sort_order}>
                                <input id="sg-order" className={inputCls} type="number" inputMode="numeric" min="0" max="65535" step="1" value={form.data.sort_order}
                                    onChange={(e) => form.setData('sort_order', e.target.value === '' ? 0 : Math.max(0, Math.trunc(Number(e.target.value))))} />
                            </Field>
                            <div className="flex items-end">
                                <label className="flex min-h-10 items-center gap-2 text-sm text-gray-700 dark:text-slate-200">
                                    <input type="checkbox" checked={form.data.is_shared} onChange={(e) => form.setData('is_shared', e.target.checked)} />{t('performance.fields.shared')}
                                </label>
                            </div>
                            <Field label={t('performance.fields.nameAm')} htmlFor="sg-name-am" error={errors.name_am} className="sm:col-span-2">
                                <input id="sg-name-am" className={inputCls} lang="am" value={form.data.name_am} maxLength={500} onChange={(e) => form.setData('name_am', e.target.value)} required />
                            </Field>
                            <Field label={t('performance.fields.nameEn')} htmlFor="sg-name-en" error={errors.name_en} className="sm:col-span-2">
                                <input id="sg-name-en" className={inputCls} lang="en" value={form.data.name_en} maxLength={500} onChange={(e) => form.setData('name_en', e.target.value)} required />
                            </Field>
                            <Field label={t('performance.fields.descriptionAm')} htmlFor="sg-desc-am" error={errors.description_am} className="sm:col-span-2">
                                <textarea id="sg-desc-am" className={inputCls} lang="am" rows={3} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />
                            </Field>
                            <Field label={t('performance.fields.descriptionEn')} htmlFor="sg-desc-en" error={errors.description_en} className="sm:col-span-2">
                                <textarea id="sg-desc-en" className={inputCls} lang="en" rows={3} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                            </Field>
                            <Field label={`${t('performance.fields.effective')} — ${t('performance.fields.from')}`} error={errors.effective_from}>
                                <LocalizedDatePicker value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} />
                            </Field>
                            <Field label={`${t('performance.fields.effective')} — ${t('performance.fields.to')}`} error={errors.effective_to}>
                                <LocalizedDatePicker value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} />
                            </Field>
                            {cycle && (
                                <p className="self-end pb-2 text-xs text-gray-500 dark:text-slate-400 sm:col-span-2">
                                    {t('performance.strategicGoals.periodHelp')} <LocalizedDateDisplay value={cycle.start_date} /> – <LocalizedDateDisplay value={cycle.end_date} />
                                </p>
                            )}
                            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                                <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{t('performance.actions.cancel')}</button>
                                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                {!contextSelected && <div className="rounded-panel border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">{t('performance.strategicGoals.selectContext')}</div>}
                {contextSelected && goals.data.length === 0 && <div className="rounded-panel border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">{t('performance.strategicGoals.empty')}</div>}

                <div className="space-y-4">
                    {goals.data.map((goal) => {
                        const description = (locale === 'am' && goal.description_am) || goal.description_en || goal.description_am;
                        const draft = goal.status === 'DRAFT';
                        return (
                            <article key={goal.id} className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900" aria-labelledby={`sg-${goal.id}-name`}>
                                <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-xs text-gray-500 dark:text-slate-400">{goal.code}</span>
                                            <Pill group="goal" value={goal.status} />
                                            {goal.is_shared && <span className="rounded-full bg-violet-50 px-2 py-1 text-xs font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-300">{t('performance.strategicGoals.shared')}</span>}
                                        </div>
                                        <h2 id={`sg-${goal.id}-name`} className="mt-2 text-base font-semibold text-gray-900 dark:text-white">{(locale === 'am' && goal.name_am) || goal.name_en}</h2>
                                        {description && <p className="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-slate-300">{description}</p>}
                                        <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-slate-400">
                                            <span className="tabular-nums">{t('performance.fields.weight')}: {formatScore(goal.weight_percent)}%</span>
                                            {(goal.effective_from || goal.effective_to) && <span><LocalizedDateDisplay value={goal.effective_from} /> – <LocalizedDateDisplay value={goal.effective_to} /></span>}
                                            <span>{t('performance.strategicGoals.objectives')}: <span className="tabular-nums">{goal.objectives.length}</span></span>
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {draft && can.update && <button type="button" className={secondaryBtn} onClick={() => start(goal)}>{t('performance.actions.edit')}</button>}
                                        {draft && can.update && <button type="button" className={primaryBtn} onClick={() => transition(goal, 'UNDER_REVIEW')}>{t('performance.actions.submit')}</button>}
                                        {['UNDER_REVIEW', 'APPROVED'].includes(goal.status) && can.approve && <button type="button" className={secondaryBtn} onClick={() => returnGoal(goal)}>{t('performance.actions.return')}</button>}
                                        {goal.status === 'UNDER_REVIEW' && can.approve && <button type="button" className={primaryBtn} onClick={() => transition(goal, 'APPROVED')}>{t('performance.actions.approve')}</button>}
                                        {goal.status === 'APPROVED' && can.publish && <button type="button" className={primaryBtn} onClick={() => transition(goal, 'PUBLISHED')}>{t('performance.actions.publish')}</button>}
                                        {draft && can.delete && <button type="button" className={dangerLinkBtn} onClick={() => removeGoal(goal)}>{t('performance.actions.remove')}</button>}
                                    </div>
                                </div>
                                {draft && goal.return_reason && <div className="px-5 pb-4"><Problems title={t('performance.strategicGoals.returned')} problems={[goal.return_reason]} /></div>}

                                <div className="border-t border-gray-100 bg-gray-50/70 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                                    <dl className="grid gap-4 sm:grid-cols-3">
                                        <div className="text-sm">
                                            <dt className="text-gray-500 dark:text-slate-400">{t('performance.strategicGoals.allocations')}</dt>
                                            <dd className="mt-1 font-semibold tabular-nums">{formatScore(goal.readiness.allocation_total)} / {formatScore(goal.weight_percent)}%</dd>
                                            <div className="mt-1.5"><Bar value={share(goal.readiness.allocation_total, goal.weight_percent)} /></div>
                                        </div>
                                        <div className="text-sm">
                                            <dt className="text-gray-500 dark:text-slate-400">{t('performance.strategicGoals.objectives')}</dt>
                                            <dd className="mt-1 font-semibold tabular-nums">{formatScore(goal.readiness.objective_total)} / {formatScore(goal.weight_percent)}%</dd>
                                            <div className="mt-1.5"><Bar value={share(goal.readiness.objective_total, goal.weight_percent)} /></div>
                                        </div>
                                        <div className="text-sm">
                                            <dt className="text-gray-500 dark:text-slate-400">{t('performance.strategicGoals.readiness')}</dt>
                                            <dd className={`mt-1 font-semibold ${goal.readiness.ready ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}`}>
                                                {goal.readiness.ready ? t('performance.strategicGoals.ready') : t('performance.strategicGoals.needsWork')}
                                            </dd>
                                        </div>
                                    </dl>

                                    {goal.allocations.length > 0 && (
                                        <ul className="mt-4 flex flex-wrap gap-2" aria-label={t('performance.strategicGoals.allocations')}>
                                            {goal.allocations.map((row) => (
                                                <li key={row.id} className="inline-flex max-w-full flex-col gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-900">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <strong>{nameOf(row.unit, locale)}</strong>
                                                        <span className="tabular-nums">{formatScore(row.organization_contribution_percent)}%</span>
                                                        <span className="text-gray-500 dark:text-slate-400">{label('allocation', row.allocation_type)}</span>
                                                        {row.is_lead && <span className="font-medium text-[color:var(--color-primary)]">{t('performance.strategicGoals.lead')}</span>}
                                                        {draft && can.allocate && (
                                                            <>
                                                                <button type="button" className={linkBtn} aria-label={`${t('performance.strategicGoals.editAllocation')}: ${nameOf(row.unit, locale)}`}
                                                                    onClick={() => setAllocating({ goal: goal.id, allocation: row.id })}>{t('performance.actions.edit')}</button>
                                                                <button type="button" className={dangerLinkBtn} aria-label={`${t('performance.strategicGoals.removeAllocation')}: ${nameOf(row.unit, locale)}`}
                                                                    title={t('performance.strategicGoals.removeAllocation')} onClick={() => removeAllocation(goal, row)}>×</button>
                                                            </>
                                                        )}
                                                    </span>
                                                    {row.notes && <span className="whitespace-pre-line text-gray-500 dark:text-slate-400">{row.notes}</span>}
                                                </li>
                                            ))}
                                        </ul>
                                    )}

                                    {goal.readiness.problems.length > 0 && (
                                        <ul className="mt-3 list-disc space-y-1 pl-5 text-xs text-amber-700 dark:text-amber-300">
                                            {goal.readiness.problems.map((problem, index) => <li key={index}>{problem}</li>)}
                                        </ul>
                                    )}

                                    {draft && can.allocate && allocating?.goal !== goal.id && (
                                        <button type="button" className={`${linkBtn} mt-3`} onClick={() => setAllocating({ goal: goal.id, allocation: null })}>{t('performance.strategicGoals.addAllocation')}</button>
                                    )}
                                    {allocating?.goal === goal.id && (
                                        <AllocationForm key={allocating.allocation ?? 'new'} goal={goal} allocation={goal.allocations.find((row) => row.id === allocating.allocation)} units={units} types={allocationTypes} onClose={() => setAllocating(null)} />
                                    )}

                                    <details className="mt-4 rounded-lg border border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                                        <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-slate-200">
                                            {t('performance.strategicGoals.objectives')} ({goal.objectives.length})
                                        </summary>
                                        {goal.objectives.length === 0 ? (
                                            <p className="px-3 pb-3 text-sm text-gray-500 dark:text-slate-400">{t('performance.strategicGoals.noObjectives')}</p>
                                        ) : (
                                            <ul className="divide-y divide-gray-100 border-t border-gray-100 text-sm dark:divide-slate-800 dark:border-slate-800">
                                                {goal.objectives.map((objective) => (
                                                    <li key={objective.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                                        <div className="min-w-0">
                                                            <p className="font-medium text-gray-900 dark:text-slate-100"><span className="font-mono text-xs text-gray-500">{objective.code}</span> {(locale === 'am' && objective.title_am) || objective.title_en}</p>
                                                            {objective.plan && (
                                                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                                                    {t('performance.fields.plan')}: <Link href={route('performance.plans.show', objective.plan.id)} className="text-[color:var(--color-primary)] hover:underline">{objective.plan.title}</Link> · {t('performance.fields.version')} {objective.plan.version} · {label('plan', objective.plan.status)}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <span className="text-xs tabular-nums text-gray-600 dark:text-slate-300">{t('performance.fields.absoluteWeight')}: {formatScore(objective.absolute_weight_percent ?? objective.weight)}%</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </details>
                                </div>
                            </article>
                        );
                    })}
                </div>
                {goals.last_page > 1 && <Pager page={goals} />}
            </div>
        </AuthenticatedLayout>
    );
}
