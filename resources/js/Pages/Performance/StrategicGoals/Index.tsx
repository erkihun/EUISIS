import PageHeader from '@/Components/PageHeader';
import { Field, Pill, Problems, Stat, dangerLinkBtn, formatScore, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, smallPrimaryBtn, type Paginator } from '@/Components/performance/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Named = { id: string; name_en: string; name_am?: string | null };
type Allocation = { id: string; organization_unit_id: string; organization_contribution_percent: string; allocation_type: string; is_lead: boolean; notes?: string | null; unit?: Named | null };
type Goal = {
    id: string; cycle_id: string; organization_id: string; code: string; name_en: string; name_am: string; description_en?: string | null; description_am?: string | null;
    weight_percent: string; is_shared: boolean; sort_order: number; status: string; objectives_count: number; allocations: Allocation[];
    readiness: { allocation_total: string; objective_total: string; remaining: string; ready: boolean; problems: string[] };
};
type Props = {
    goals: Paginator<Goal>; filters: { cycle_id: string; organization_id: string };
    cycles: (Named & { code: string; organization_id?: string | null })[]; organizations: Named[]; units: (Named & { organization_id: string })[];
    summary: { total: string; remaining: string; ready: boolean; problems: string[] } | null; allocationTypes: string[];
    can: { create: boolean; update: boolean; delete: boolean; allocate: boolean; approve: boolean; publish: boolean };
};

function AllocationForm({ goal, units, types, onClose }: { goal: Goal; units: Props['units']; types: string[]; onClose: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({ organization_unit_id: '', organization_contribution_percent: '', allocation_type: 'PRIMARY', is_lead: goal.allocations.length === 0, notes: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const available = units.filter((unit) => unit.organization_id === goal.organization_id && !goal.allocations.some((row) => row.organization_unit_id === unit.id));
    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('performance.strategic-goals.allocations.store', goal.id), { preserveScroll: true, onSuccess: onClose });
    }
    return <form onSubmit={submit} className="mt-4 grid gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-950/50 sm:grid-cols-2 lg:grid-cols-5">
        <Field label={t('performance.fields.unit')} error={errors.organization_unit_id}>
            <select className={inputCls} value={form.data.organization_unit_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)} required><option value="">—</option>{available.map((u) => <option key={u.id} value={u.id}>{nameOf(u, locale)}</option>)}</select>
        </Field>
        <Field label={t('performance.fields.contribution')} error={errors.organization_contribution_percent}>
            <input className={inputCls} type="number" min="0.0001" max="100" step="0.0001" value={form.data.organization_contribution_percent} onChange={(e) => form.setData('organization_contribution_percent', e.target.value)} required />
        </Field>
        <Field label={t('performance.fields.allocationType')} error={errors.allocation_type}>
            <select className={inputCls} value={form.data.allocation_type} onChange={(e) => form.setData('allocation_type', e.target.value)}>{types.map((type) => <option key={type} value={type}>{t(`performance.enums.allocation.${type}`)}</option>)}</select>
        </Field>
        <label className="flex items-center gap-2 pt-7 text-sm text-gray-700 dark:text-slate-200"><input type="checkbox" checked={form.data.is_lead} onChange={(e) => form.setData('is_lead', e.target.checked)} />{t('performance.fields.leadUnit')}</label>
        <div className="flex items-end justify-end gap-2"><button type="button" className={secondaryBtn} onClick={onClose}>{t('performance.actions.cancel')}</button><button className={smallPrimaryBtn} disabled={form.processing}>{t('performance.actions.add')}</button></div>
    </form>;
}

export default function StrategicGoalsIndex({ goals, filters, cycles, organizations, units, summary, allocationTypes, can }: Props) {
    const { t, locale } = useLocale();
    const [editing, setEditing] = useState<Goal | 'new' | null>(null);
    const [allocating, setAllocating] = useState<string | null>(null);
    const blank = { cycle_id: filters.cycle_id, organization_id: filters.organization_id, code: '', name_en: '', name_am: '', description_en: '', description_am: '', weight_percent: '', is_shared: false, sort_order: 0 };
    const form = useForm(blank);
    const errors = form.errors as Record<string, string | undefined>;

    function filter(key: 'cycle_id' | 'organization_id', value: string) {
        router.get(route('performance.strategic-goals.index'), { ...filters, [key]: value }, { preserveState: true, replace: true });
    }
    function start(goal: Goal | 'new') {
        form.clearErrors();
        form.setData(goal === 'new' ? blank : {
            cycle_id: goal.cycle_id, organization_id: goal.organization_id, code: goal.code, name_en: goal.name_en, name_am: goal.name_am,
            description_en: goal.description_en ?? '', description_am: goal.description_am ?? '', weight_percent: goal.weight_percent, is_shared: goal.is_shared, sort_order: goal.sort_order,
        });
        setEditing(goal);
    }
    function submit(e: FormEvent) {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing !== 'new' && editing) {
            form.transform(({ cycle_id: _cycle, organization_id: _organization, ...data }) => data);
            form.put(route('performance.strategic-goals.update', editing.id), done);
        } else {
            form.transform((data) => data);
            form.post(route('performance.strategic-goals.store'), done);
        }
    }
    function transition(goal: Goal, status: string) {
        router.post(route('performance.strategic-goals.transition', goal.id), { status }, { preserveScroll: true });
    }

    const contextSelected = Boolean(filters.cycle_id && filters.organization_id);
    return <AuthenticatedLayout header={<PageHeader title={t('performance.strategicGoals.title')} description={t('performance.strategicGoals.description')} actions={can.create && contextSelected ? <button className={primaryBtn} onClick={() => start('new')}>{t('performance.strategicGoals.create')}</button> : undefined} />}>
        <Head title={t('performance.strategicGoals.title')} />
        <div className={pageCls}>
            <section className="grid gap-4 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                <Field label={t('performance.fields.cycle')}><select className={inputCls} value={filters.cycle_id} onChange={(e) => filter('cycle_id', e.target.value)}><option value="">—</option>{cycles.map((c) => <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)}</option>)}</select></Field>
                <Field label={t('performance.fields.organization')}><select className={inputCls} value={filters.organization_id} onChange={(e) => filter('organization_id', e.target.value)}><option value="">—</option>{organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}</select></Field>
            </section>

            {summary && <><div className="grid gap-4 sm:grid-cols-3"><Stat label={t('performance.strategicGoals.total')} value={summary.total} suffix="%" /><Stat label={t('performance.strategicGoals.remaining')} value={summary.remaining} suffix="%" /><Stat label={t('performance.strategicGoals.readiness')} value={summary.ready ? t('performance.strategicGoals.ready') : t('performance.strategicGoals.needsWork')} /></div><Problems title={t('performance.strategicGoals.readiness')} problems={summary.problems} /></>}

            {editing && <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('performance.fields.code')} error={errors.code}><input className={inputCls} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required /></Field>
                    <Field label={t('performance.fields.nameEn')} error={errors.name_en}><input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required /></Field>
                    <Field label={t('performance.fields.nameAm')} error={errors.name_am}><input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} required /></Field>
                    <Field label={t('performance.fields.weight')} error={errors.weight_percent}><input className={inputCls} type="number" min="0.0001" max="100" step="0.0001" value={form.data.weight_percent} onChange={(e) => form.setData('weight_percent', e.target.value)} required /></Field>
                    <Field label={t('performance.fields.description')} className="sm:col-span-2"><textarea className={inputCls} rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} /></Field>
                    <label className="flex items-center gap-2 pt-7 text-sm"><input type="checkbox" checked={form.data.is_shared} onChange={(e) => form.setData('is_shared', e.target.checked)} />{t('performance.fields.shared')}</label>
                    <div className="flex items-end justify-end gap-2"><button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{t('performance.actions.cancel')}</button><button className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button></div>
                </form>
            </section>}

            {!contextSelected && <div className="rounded-panel border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">{t('performance.strategicGoals.selectContext')}</div>}
            {contextSelected && goals.data.length === 0 && <div className="rounded-panel border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">{t('performance.strategicGoals.empty')}</div>}
            <div className="space-y-4">{goals.data.map((goal) => <article key={goal.id} className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                    <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><span className="font-mono text-xs text-gray-500">{goal.code}</span><Pill group="goal" value={goal.status} />{goal.is_shared && <span className="rounded-full bg-violet-50 px-2 py-1 text-xs font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-300">{t('performance.strategicGoals.shared')}</span>}</div><h2 className="mt-2 text-base font-semibold text-gray-900 dark:text-white">{(locale === 'am' && goal.name_am) || goal.name_en}</h2><p className="mt-1 text-xs text-gray-500">{formatScore(goal.weight_percent)}% · {goal.objectives_count} {t('performance.strategicGoals.objectives')}</p></div>
                    <div className="flex flex-wrap gap-2">
                        {goal.status === 'DRAFT' && can.update && <button className={secondaryBtn} onClick={() => start(goal)}>{t('performance.actions.edit')}</button>}
                        {goal.status === 'DRAFT' && can.update && <button className={primaryBtn} onClick={() => transition(goal, 'UNDER_REVIEW')}>{t('performance.actions.submit')}</button>}
                        {goal.status === 'UNDER_REVIEW' && can.approve && <button className={primaryBtn} onClick={() => transition(goal, 'APPROVED')}>{t('performance.actions.approve')}</button>}
                        {goal.status === 'APPROVED' && can.publish && <button className={primaryBtn} onClick={() => transition(goal, 'PUBLISHED')}>{t('performance.actions.publish')}</button>}
                        {goal.status === 'DRAFT' && can.delete && <button className={dangerLinkBtn} onClick={() => confirm(t('performance.confirm')) && router.delete(route('performance.strategic-goals.destroy', goal.id), { preserveScroll: true })}>{t('performance.actions.remove')}</button>}
                    </div>
                </div>
                <div className="border-t border-gray-100 bg-gray-50/70 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                    <div className="grid gap-3 sm:grid-cols-3"><div className="text-sm"><span className="text-gray-500">{t('performance.strategicGoals.allocations')}</span><strong className="ml-2 tabular-nums">{formatScore(goal.readiness.allocation_total)} / {formatScore(goal.weight_percent)}%</strong></div><div className="text-sm"><span className="text-gray-500">{t('performance.strategicGoals.objectives')}</span><strong className="ml-2 tabular-nums">{formatScore(goal.readiness.objective_total)}%</strong></div><div className={`text-sm font-medium ${goal.readiness.ready ? 'text-emerald-600' : 'text-amber-600'}`}>{goal.readiness.ready ? t('performance.strategicGoals.ready') : t('performance.strategicGoals.needsWork')}</div></div>
                    <div className="mt-3 flex flex-wrap gap-2">{goal.allocations.map((row) => <span key={row.id} className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-900"><strong>{nameOf(row.unit, locale)}</strong><span>{formatScore(row.organization_contribution_percent)}%</span>{row.is_lead && <span className="text-[color:var(--color-primary)]">{t('performance.strategicGoals.lead')}</span>}{goal.status === 'DRAFT' && can.allocate && <button className={dangerLinkBtn} onClick={() => router.delete(route('performance.strategic-goal-allocations.destroy', row.id), { preserveScroll: true })}>×</button>}</span>)}</div>
                    {goal.readiness.problems.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-xs text-amber-700 dark:text-amber-300">{goal.readiness.problems.map((problem) => <li key={problem}>{problem}</li>)}</ul>}
                    {goal.status === 'DRAFT' && can.allocate && <button className="mt-3 text-xs font-medium text-[color:var(--color-primary)]" onClick={() => setAllocating(allocating === goal.id ? null : goal.id)}>{t('performance.strategicGoals.addAllocation')}</button>}
                    {allocating === goal.id && <AllocationForm goal={goal} units={units} types={allocationTypes} onClose={() => setAllocating(null)} />}
                </div>
            </article>)}</div>
        </div>
    </AuthenticatedLayout>;
}
