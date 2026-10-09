import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { CycleForm, type CycleData, type Option } from '@/Components/assessmentOversight/CycleForm';
import { named } from '@/Components/assessmentOversight/shell';
import { Field, Section, Table, TablePanel, inputCls, linkBtn, pageCls, primaryBtn, secondaryBtn, smallBtn, smallPrimaryBtn, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { StatusBadge as UiStatusBadge, Tabs } from '@euisis/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type BandDraft = { code: string; label_en: string; label_am: string | null; min_score: string; max_score: string; min_inclusive: boolean; max_inclusive: boolean };
type Policy = { id: string; code: string; version_no: number; name_en: string; name_am: string | null; status: string; range_min: string; range_max: string; requires_full_coverage: boolean; activated_at: string | null; bands: BandDraft[]; problems: string[] };
type Reason = { id: string; code: string; name_en: string; name_am: string | null; source: string; requires_approval: boolean; excludes_from_denominator: boolean; is_system: boolean; is_active: boolean; sort_order: number };

type Props = {
    cycles: Paginator<CycleData & { type: Option | null; organizations_count: number }>;
    policies: Policy[];
    reasons: Reason[];
    types: Option[];
    employeeStatuses: string[];
    can: { cycles: boolean; policies: boolean };
};

/** Oversight configuration: cycles, versioned result bands, unassessed reasons. No Form Builder actions here. */
export default function OversightSetup({ cycles, policies, reasons, types, employeeStatuses, can }: Props) {
    const { t, locale } = useLocale();
    const [creating, setCreating] = useState(false);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('assessmentOversight.setup.title')} description={t('assessmentOversight.setup.description')}
            actions={<Link href={route('assessment-oversight.dashboard')} className={secondaryBtn}>{t('assessmentOversight.nav.dashboard')}</Link>} />}>
            <Head title={t('assessmentOversight.setup.title')} />
            <div className={pageCls}>
                {/* Shared admin tabs (same as System › Backup & Recovery). */}
                <Tabs items={[
                    ...(can.cycles ? [{ id: 'cycles', label: t('assessmentOversight.setup.tabs.cycles'), content: (
                <>
                    {creating
                        ? <Section title={t('assessmentOversight.setup.newCycle')}><CycleForm types={types} policies={policies.filter((p) => p.status === 'active')} employeeStatuses={employeeStatuses} onCancel={() => setCreating(false)} /></Section>
                        : <div className="mb-4"><button type="button" className={primaryBtn} onClick={() => setCreating(true)}>{t('assessmentOversight.setup.newCycle')}</button></div>}
                    <TablePanel page={cycles} empty={t('assessmentOversight.setup.noCycles')}>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.cycle')}</th>
                            <th className={thCls}>{t('assessmentOversight.setup.type')}</th>
                            <th className={thCls}>{t('assessmentOversight.setup.period')}</th>
                            <th className={thCls}>{t('assessmentOversight.setup.institutions')}</th>
                            <th className={thCls}>{t('assessmentOversight.status')}</th>
                            <th className={thCls}>{t('assessmentOversight.eligibility')}</th>
                            <th className={thCls}><span className="sr-only">{t('assessmentOversight.actionsLabel')}</span></th>
                        </>}>
                            {cycles.data.map((c) => (
                                <tr key={c.id}>
                                    <td className={tdCls}><span className="font-medium">{named(c, locale)}</span><div className="text-xs text-gray-500">{c.code}</div></td>
                                    <td className={tdCls}>{named(c.type, locale)}</td>
                                    <td className={`${tdCls} text-xs`}><LocalizedDateDisplay value={c.period_start} /> – <LocalizedDateDisplay value={c.period_end} /></td>
                                    <td className={`${tdCls} tabular-nums`}>{c.organizations_count}</td>
                                    <td className={tdCls}><UiStatusBadge tone={c.status === 'active' ? 'success' : c.status === 'draft' ? 'info' : 'neutral'}>{t(`assessmentOversight.cycleStatuses.${c.status}`)}</UiStatusBadge></td>
                                    <td className={tdCls}><UiStatusBadge tone={c.eligibility_status === 'finalized' ? 'success' : 'warning'}>{t(`assessmentOversight.eligibilityStatuses.${c.eligibility_status}`)}</UiStatusBadge></td>
                                    <td className={`${tdCls} text-right`}><Link className={linkBtn} href={route('assessment-oversight.cycles.show', c.id)}>{t('assessmentOversight.open')}</Link></td>
                                </tr>
                            ))}
                        </Table>
                    </TablePanel>
                </>
                    ) }] : []),
                    ...(can.policies ? [
                        { id: 'bands', label: t('assessmentOversight.setup.tabs.bands'), content: <div className="space-y-4"><Policies policies={policies} /></div> },
                        { id: 'reasons', label: t('assessmentOversight.setup.tabs.reasons'), content: <div className="space-y-4"><Reasons reasons={reasons} /></div> },
                    ] : []),
                ]} />
            </div>
        </AuthenticatedLayout>
    );
}

function Policies({ policies }: { policies: Policy[] }) {
    const { t, locale } = useLocale();
    const create = useForm({ code: '', name_en: '', name_am: '' });
    const [editing, setEditing] = useState<string | null>(null);
    return (
        <>
            <Section title={t('assessmentOversight.setup.bandsTitle')} description={t('assessmentOversight.setup.bandsHelp')}>
                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); create.post(route('assessment-oversight.band-policies.store'), { preserveScroll: true, onSuccess: () => create.reset() }); }}>
                    <Field label={t('assessmentOversight.setup.code')} error={create.errors.code}><input className={inputCls} required value={create.data.code} onChange={(e) => create.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label={t('assessmentOversight.setup.nameEn')} error={create.errors.name_en}><input className={inputCls} required value={create.data.name_en} onChange={(e) => create.setData('name_en', e.target.value)} /></Field>
                    <Field label={t('assessmentOversight.setup.nameAm')}><input className={inputCls} value={create.data.name_am} onChange={(e) => create.setData('name_am', e.target.value)} /></Field>
                    <div className="flex items-end"><button className={primaryBtn} disabled={create.processing}>{t('assessmentOversight.setup.newPolicy')}</button></div>
                </form>
            </Section>
            {policies.map((p) => (
                <Section key={p.id} title={`${named(p, locale)} · ${p.code} v${p.version_no}`} description={t(`assessmentOversight.setup.policyStatuses.${p.status}`)}
                    actions={<div className="flex gap-2">
                        {p.status === 'draft' && <button type="button" className={smallBtn} onClick={() => setEditing(editing === p.id ? null : p.id)}>{t('assessmentOversight.setup.editBands')}</button>}
                        {p.status === 'draft' && <button type="button" className={smallPrimaryBtn} disabled={p.problems.length > 0} onClick={() => router.post(route('assessment-oversight.band-policies.activate', p.id), {}, { preserveScroll: true })}>{t('assessmentOversight.setup.activate')}</button>}
                        {p.status !== 'draft' && <button type="button" className={smallBtn} onClick={() => router.post(route('assessment-oversight.band-policies.versions', p.id), {}, { preserveScroll: true })}>{t('assessmentOversight.setup.newVersion')}</button>}
                    </div>}>
                    {editing === p.id ? <BandEditor policy={p} onDone={() => setEditing(null)} /> : (
                        <>
                            <ul className="text-sm">
                                {p.bands.length === 0 && <li className="text-gray-500">{t('assessmentOversight.setup.noBands')}</li>}
                                {p.bands.map((b) => <li key={b.code} className="flex justify-between border-b border-gray-100 py-1 dark:border-slate-800"><span>{(locale === 'am' && b.label_am) || b.label_en}</span><span className="tabular-nums text-gray-600">{b.min_inclusive ? '[' : '('}{Number(b.min_score)} – {Number(b.max_score)}{b.max_inclusive ? ']' : ')'}</span></li>)}
                            </ul>
                            {p.problems.length > 0 && <ul className="mt-2 list-disc ps-5 text-sm text-red-700">{p.problems.map((x) => <li key={x}>{x}</li>)}</ul>}
                        </>
                    )}
                </Section>
            ))}
        </>
    );
}

function BandEditor({ policy, onDone }: { policy: Policy; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({
        name_en: policy.name_en, name_am: policy.name_am ?? '', range_min: policy.range_min, range_max: policy.range_max, requires_full_coverage: policy.requires_full_coverage,
        bands: policy.bands.length ? policy.bands : [{ code: '', label_en: '', label_am: '', min_score: '0', max_score: '100', min_inclusive: true, max_inclusive: true }] as BandDraft[],
    });
    const setBand = (i: number, patch: Partial<BandDraft>) => form.setData('bands', form.data.bands.map((b, n) => (n === i ? { ...b, ...patch } : b)));
    return (
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.put(route('assessment-oversight.band-policies.update', policy.id), { preserveScroll: true, onSuccess: onDone }); }}>
            <div className="grid gap-3 sm:grid-cols-4">
                <Field label={t('assessmentOversight.setup.nameEn')} error={form.errors.name_en}><input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
                <Field label={t('assessmentOversight.setup.rangeMin')}><input type="number" step="0.0001" className={inputCls} value={form.data.range_min} onChange={(e) => form.setData('range_min', e.target.value)} /></Field>
                <Field label={t('assessmentOversight.setup.rangeMax')}><input type="number" step="0.0001" className={inputCls} value={form.data.range_max} onChange={(e) => form.setData('range_max', e.target.value)} /></Field>
                <label className="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.data.requires_full_coverage} onChange={(e) => form.setData('requires_full_coverage', e.target.checked)} />{t('assessmentOversight.setup.fullCoverage')}</label>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead className="text-xs text-gray-500"><tr>
                        <th className="p-1 text-left">{t('assessmentOversight.setup.code')}</th><th className="p-1 text-left">{t('assessmentOversight.setup.labelEn')}</th><th className="p-1 text-left">{t('assessmentOversight.setup.labelAm')}</th>
                        <th className="p-1 text-left">{t('assessmentOversight.setup.min')}</th><th className="p-1 text-left">{t('assessmentOversight.setup.minInclusive')}</th>
                        <th className="p-1 text-left">{t('assessmentOversight.setup.max')}</th><th className="p-1 text-left">{t('assessmentOversight.setup.maxInclusive')}</th><th />
                    </tr></thead>
                    <tbody>
                        {form.data.bands.map((b, i) => (
                            <tr key={i}>
                                <td className="p-1"><input aria-label={t('assessmentOversight.setup.code')} className={inputCls} value={b.code} onChange={(e) => setBand(i, { code: e.target.value.toUpperCase() })} /></td>
                                <td className="p-1"><input aria-label={t('assessmentOversight.setup.labelEn')} className={inputCls} value={b.label_en} onChange={(e) => setBand(i, { label_en: e.target.value })} /></td>
                                <td className="p-1"><input aria-label={t('assessmentOversight.setup.labelAm')} className={inputCls} value={b.label_am ?? ''} onChange={(e) => setBand(i, { label_am: e.target.value })} /></td>
                                <td className="p-1"><input aria-label={t('assessmentOversight.setup.min')} type="number" step="0.0001" className={inputCls} value={b.min_score} onChange={(e) => setBand(i, { min_score: e.target.value })} /></td>
                                <td className="p-1 text-center"><input aria-label={t('assessmentOversight.setup.minInclusive')} type="checkbox" checked={b.min_inclusive} onChange={(e) => setBand(i, { min_inclusive: e.target.checked })} /></td>
                                <td className="p-1"><input aria-label={t('assessmentOversight.setup.max')} type="number" step="0.0001" className={inputCls} value={b.max_score} onChange={(e) => setBand(i, { max_score: e.target.value })} /></td>
                                <td className="p-1 text-center"><input aria-label={t('assessmentOversight.setup.maxInclusive')} type="checkbox" checked={b.max_inclusive} onChange={(e) => setBand(i, { max_inclusive: e.target.checked })} /></td>
                                <td className="p-1"><button type="button" className={smallBtn} onClick={() => form.setData('bands', form.data.bands.filter((_, n) => n !== i))}>{t('assessmentOversight.actions.remove')}</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {Object.entries(form.errors).map(([k, v]) => <p key={k} className="text-sm text-red-600">{v}</p>)}
            <div className="flex gap-2">
                <button type="button" className={smallBtn} onClick={() => form.setData('bands', [...form.data.bands, { code: '', label_en: '', label_am: '', min_score: '0', max_score: '0', min_inclusive: true, max_inclusive: false }])}>{t('assessmentOversight.setup.addBand')}</button>
                <button className={smallPrimaryBtn} disabled={form.processing}>{t('assessmentOversight.actions.save')}</button>
                <button type="button" className={smallBtn} onClick={onDone}>{t('assessmentOversight.actions.cancel')}</button>
            </div>
            <p className="text-xs text-gray-500">{t('assessmentOversight.setup.bandsNote')}</p>
        </form>
    );
}

function Reasons({ reasons }: { reasons: Reason[] }) {
    const { t, locale } = useLocale();
    const blank = { code: '', name_en: '', name_am: '', source: 'approved_exception', requires_approval: true, excludes_from_denominator: false, is_active: true, sort_order: 100 };
    const form = useForm<typeof blank & { id?: string }>(blank);
    const edit = (r: Reason) => form.setData({ id: r.id, code: r.code, name_en: r.name_en, name_am: r.name_am ?? '', source: r.source, requires_approval: r.requires_approval, excludes_from_denominator: r.excludes_from_denominator, is_active: r.is_active, sort_order: r.sort_order });
    const save = () => (form.data.id
        ? form.put(route('assessment-oversight.reasons.update', form.data.id), { preserveScroll: true, onSuccess: () => form.setData(blank) })
        : form.post(route('assessment-oversight.reasons.store'), { preserveScroll: true, onSuccess: () => form.setData(blank) }));
    return (
        <>
            <Section title={t('assessmentOversight.setup.reasonsTitle')} description={t('assessmentOversight.setup.reasonsHelp')}>
                <form className="grid gap-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); save(); }}>
                    <Field label={t('assessmentOversight.setup.code')} error={form.errors.code}><input className={inputCls} disabled={!!form.data.id} required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toLowerCase())} /></Field>
                    <Field label={t('assessmentOversight.setup.nameEn')} error={form.errors.name_en}><input className={inputCls} required value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
                    <Field label={t('assessmentOversight.setup.nameAm')}><input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
                    <Field label={t('assessmentOversight.setup.source')}>
                        <select className={inputCls} value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                            {form.data.source === 'system_detected' && <option value="system_detected" disabled>{t('assessmentOversight.reasonSources.system_detected')}</option>}
                            <option value="approved_exception">{t('assessmentOversight.reasonSources.approved_exception')}</option>
                            <option value="institution_reported">{t('assessmentOversight.reasonSources.institution_reported')}</option>
                        </select>
                    </Field>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.excludes_from_denominator} onChange={(e) => form.setData('excludes_from_denominator', e.target.checked)} />{t('assessmentOversight.setup.excludesFromDenominator')}</label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />{t('assessmentOversight.setup.active')}</label>
                    <div className="flex gap-2 sm:col-span-3">
                        <button className={primaryBtn} disabled={form.processing}>{t('assessmentOversight.actions.save')}</button>
                        {form.data.id && <button type="button" className={secondaryBtn} onClick={() => form.setData(blank)}>{t('assessmentOversight.actions.cancel')}</button>}
                    </div>
                    <p className="text-xs text-gray-500 sm:col-span-3">{t('assessmentOversight.setup.excludesHelp')}</p>
                </form>
            </Section>
            <Section title={t('assessmentOversight.setup.tabs.reasons')} flush>
                <Table head={<>
                    <th className={thCls}>{t('assessmentOversight.setup.code')}</th>
                    <th className={thCls}>{t('assessmentOversight.reason')}</th>
                    <th className={thCls}>{t('assessmentOversight.setup.source')}</th>
                    <th className={thCls}>{t('assessmentOversight.setup.excludesFromDenominator')}</th>
                    <th className={thCls}>{t('assessmentOversight.setup.active')}</th>
                    <th className={thCls}><span className="sr-only">{t('assessmentOversight.actionsLabel')}</span></th>
                </>}>
                    {reasons.map((r) => (
                        <tr key={r.id}>
                            <td className={`${tdCls} font-mono text-xs`}>{r.code}{r.is_system && <span className="ms-1 text-gray-400">({t('assessmentOversight.system')})</span>}</td>
                            <td className={tdCls}>{named(r, locale)}</td>
                            <td className={tdCls}>{t(`assessmentOversight.reasonSources.${r.source}`)}</td>
                            <td className={tdCls}>{r.excludes_from_denominator ? '✓' : '—'}</td>
                            <td className={tdCls}>{r.is_active ? '✓' : '—'}</td>
                            <td className={tdCls}><button type="button" className={linkBtn} onClick={() => edit(r)}>{t('assessmentOversight.actions.edit')}</button></td>
                        </tr>
                    ))}
                </Table>
            </Section>
        </>
    );
}
