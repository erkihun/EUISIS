import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import Lookup from '@/Components/performance/Lookup';
import { Empty, Field, Pager, Pill, Section, Table, compactInputCls, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, titleOf, useEnumLabel, type Bilingual, type BilingualTitle, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

export type PlanSummary = {
    id: string; title: string; type: string; status: string; version: number;
    organization: Bilingual; unit: Bilingual; position: BilingualTitle; cycle: Bilingual; published_at: string | null;
};

type Props = {
    plans: Paginator<PlanSummary>;
    filters: { cycle_id?: string; plan_type?: string; status?: string; history?: string };
    cycles: { id: string; code: string; name_en: string; name_am: string | null; organization_id: string | null; status: string }[];
    types: string[];
    statuses: string[];
    publishedParents: { id: string; title: string; type: string; cycle_id: string; organization_id: string; unit: string | null }[];
    organizations: { id: string; name_en: string; name_am: string | null }[];
    can: { create: boolean };
};

type Unit = { id: string; name_en: string; name_am: string | null };
type Position = { id: string; title_en: string; title_am: string | null; job_position_code: string | null };

/** Plans list with filters; creating a plan starts it as a DRAFT under its parent. */
export default function PlansIndex({ plans, filters, cycles, types, statuses, publishedParents, organizations, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [open, setOpen] = useState(false);
    const [unitName, setUnitName] = useState('');
    const [positionName, setPositionName] = useState('');
    const form = useForm({
        cycle_id: cycles[0]?.id ?? '', plan_type: 'ORGANIZATION', organization_id: organizations[0]?.id ?? '', organization_unit_id: '', position_id: '',
        parent_plan_id: '', title: '', effective_from: '', effective_to: '',
    });

    const parents = publishedParents.filter((p) => p.cycle_id === form.data.cycle_id && p.organization_id === form.data.organization_id
        && (form.data.plan_type === 'UNIT' ? true : form.data.plan_type === 'POSITION' ? p.type === 'UNIT' || p.type === 'ORGANIZATION' : false));

    function filter(key: string, value: string) {
        router.get(route('performance.plans.index'), { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('performance.plans.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.plans.title')} description={t('performance.plans.description')}
            actions={can.create && <button type="button" className={primaryBtn} onClick={() => setOpen((v) => !v)}>{t('performance.plans.create')}</button>} />}>
            <Head title={t('performance.plans.title')} />
            <div className={pageCls}>
                {open && (
                    <Section title={t('performance.plans.create')}>
                        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <Field label={t('performance.fields.cycle')} htmlFor="p-cycle" error={form.errors.cycle_id}>
                                <select id="p-cycle" className={inputCls} value={form.data.cycle_id} onChange={(e) => form.setData('cycle_id', e.target.value)} required>
                                    {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)} ({label('cycle', c.status)})</option>)}
                                </select>
                            </Field>
                            <Field label={t('performance.fields.type')} htmlFor="p-type" error={form.errors.plan_type}>
                                <select id="p-type" className={inputCls} value={form.data.plan_type} onChange={(e) => form.setData({ ...form.data, plan_type: e.target.value, parent_plan_id: '', organization_unit_id: '', position_id: '' })}>
                                    {types.map((v) => <option key={v} value={v}>{label('planType', v)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('performance.fields.organization')} htmlFor="p-org" error={form.errors.organization_id}>
                                <select id="p-org" className={inputCls} value={form.data.organization_id} onChange={(e) => { form.setData({ ...form.data, organization_id: e.target.value, organization_unit_id: '', position_id: '', parent_plan_id: '' }); setUnitName(''); setPositionName(''); }} required>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                                </select>
                            </Field>
                            {form.data.plan_type !== 'ORGANIZATION' && (
                                <Field label={t('performance.fields.unit')} htmlFor="p-unit" error={form.errors.organization_unit_id}>
                                    <Lookup<Unit> id="p-unit" url={route('performance.lookups.units')} params={{ organization_id: form.data.organization_id }}
                                        value={form.data.organization_unit_id} display={unitName} render={(u) => nameOf(u, locale)} keyOf={(u) => u.id}
                                        onChange={(id, u) => { form.setData('organization_unit_id', id); setUnitName(u ? nameOf(u, locale) : ''); }} placeholder={t('performance.actions.search')} />
                                </Field>
                            )}
                            {form.data.plan_type === 'POSITION' && (
                                <Field label={t('performance.fields.position')} htmlFor="p-pos" error={form.errors.position_id}>
                                    <Lookup<Position> id="p-pos" url={route('performance.lookups.positions')} params={{ organization_id: form.data.organization_id, organization_unit_id: form.data.organization_unit_id || undefined }}
                                        value={form.data.position_id} display={positionName} render={(p) => `${titleOf(p, locale)}${p.job_position_code ? ` (${p.job_position_code})` : ''}`} keyOf={(p) => p.id}
                                        onChange={(id, p) => { form.setData('position_id', id); setPositionName(p ? titleOf(p, locale) : ''); }} placeholder={t('performance.actions.search')} />
                                </Field>
                            )}
                            {form.data.plan_type !== 'ORGANIZATION' && (
                                <Field label={t('performance.fields.parentPlan')} htmlFor="p-parent" error={form.errors.parent_plan_id}>
                                    <select id="p-parent" className={inputCls} value={form.data.parent_plan_id} onChange={(e) => form.setData('parent_plan_id', e.target.value)} required>
                                        <option value="">—</option>
                                        {parents.map((p) => <option key={p.id} value={p.id}>{p.title}{p.unit ? ` · ${p.unit}` : ''} ({label('planType', p.type)})</option>)}
                                    </select>
                                </Field>
                            )}
                            <Field label={t('performance.fields.title')} htmlFor="p-title" error={form.errors.title} className="sm:col-span-2 lg:col-span-1">
                                <input id="p-title" className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required />
                            </Field>
                            <Field label={`${t('performance.fields.effective')} — ${t('performance.fields.from')}`} error={form.errors.effective_from}>
                                <LocalizedDatePicker value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} />
                            </Field>
                            <Field label={`${t('performance.fields.effective')} — ${t('performance.fields.to')}`} error={form.errors.effective_to}>
                                <LocalizedDatePicker value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} />
                            </Field>
                            <div className="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                                <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.create')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                <Section title={t('performance.plans.title')} actions={
                    <div className="flex flex-wrap gap-2">
                        <select aria-label={t('performance.fields.cycle')} className={compactInputCls} value={filters.cycle_id ?? ''} onChange={(e) => filter('cycle_id', e.target.value)}>
                            <option value="">{t('performance.fields.cycle')}: —</option>
                            {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}
                        </select>
                        <select aria-label={t('performance.fields.type')} className={compactInputCls} value={filters.plan_type ?? ''} onChange={(e) => filter('plan_type', e.target.value)}>
                            <option value="">{t('performance.fields.type')}: —</option>
                            {types.map((v) => <option key={v} value={v}>{label('planType', v)}</option>)}
                        </select>
                        <select aria-label={t('performance.fields.status')} className={compactInputCls} value={filters.status ?? ''} onChange={(e) => filter('status', e.target.value)}>
                            <option value="">{t('performance.fields.status')}: —</option>
                            {statuses.map((v) => <option key={v} value={v}>{label('plan', v)}</option>)}
                        </select>
                        <label className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-slate-400">
                            <input type="checkbox" checked={filters.history === '1'} onChange={(e) => filter('history', e.target.checked ? '1' : '')} />{t('performance.fields.history')}
                        </label>
                    </div>
                }>
                    {plans.data.length === 0 ? <Empty>{t('performance.plans.empty')}</Empty> : (
                        <Table head={<>
                            <th className={thCls}>{t('performance.fields.title')}</th>
                            <th className={thCls}>{t('performance.fields.type')}</th>
                            <th className={thCls}>{t('performance.fields.cycle')}</th>
                            <th className={thCls}>{t('performance.fields.version')}</th>
                            <th className={thCls}>{t('performance.fields.status')}</th>
                        </>}>
                            {plans.data.map((plan) => (
                                <tr key={plan.id}>
                                    <td className={tdCls}>
                                        <Link href={route('performance.plans.show', plan.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{plan.title}</Link>
                                        <p className="text-xs text-gray-500 dark:text-slate-400">{[nameOf(plan.organization, locale), nameOf(plan.unit, locale), titleOf(plan.position, locale)].filter(Boolean).join(' › ')}</p>
                                    </td>
                                    <td className={tdCls}>{label('planType', plan.type)}</td>
                                    <td className={tdCls}>{nameOf(plan.cycle, locale)}</td>
                                    <td className={`${tdCls} tabular-nums`}>v{plan.version}</td>
                                    <td className={tdCls}><Pill group="plan" value={plan.status} /></td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <Pager page={plans} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
