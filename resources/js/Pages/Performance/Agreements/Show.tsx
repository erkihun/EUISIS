import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import Lookup from '@/Components/performance/Lookup';
import { assignmentPlace } from '@/Pages/Performance/Agreements/Index';
import { ActualsList, CheckinList, DevelopmentPlans, EvidenceList, ItemsTable, ResultPanel, ReviewSummary, agreementHeading, ratingLevels, type AgreementItem, type AgreementView } from '@/Components/performance/agreement';
import { ActualForm, AmendmentDiff, AmendmentForm, DevelopmentPlanForm, EvidenceForm, nullify } from '@/Components/performance/forms';
import { Field, Pill, Problems, Section, employeeName, formatScore, inputCls, pageCls, primaryBtn, secondaryBtn, smallBtn, useEnumLabel } from '@/Components/performance/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Props = {
    agreement: AgreementView;
    planTargets: { id: string; kpi_id: string; kpi_code: string; kpi_name_en: string; kpi_name_am: string | null; target_value: string | null; objective_id: string | null }[];
    validation: string[];
    pendingAdjustments: { id: string; original_score: string | null; adjusted_score: string | null; reason: string; requested_by: number }[];
    pendingAmendments: { id: string; subject_id: string; original_values: Record<string, unknown> | null; proposed_values: Record<string, unknown> | null; reason: string; effective_date: string }[];
    recommendPip: boolean;
    combined: { score: string | null; parts: { agreement_id: string; days: string; final_score: string | null }[]; formula: string };
    can: { manage: boolean; edit: boolean; approve: boolean; enterActual: boolean; verify: boolean; review: boolean; checkin: boolean; finalize: boolean; adjust: boolean; amend: boolean };
};

const MANUAL_SOURCES = ['MANUAL', 'DOCUMENT_EVIDENCE', 'SURVEY'];
const SYNC_SOURCES = ['DAILY_ACTIVITY', 'SYSTEM_TRANSACTION'];
const LIVE = ['AGREED', 'ACTIVE', 'UNDER_REVIEW'];

/** Manager / HR view of one employee agreement: KPIs, measurement, check-ins, reviews and the result. */
export default function AgreementShow({ agreement, planTargets, validation, pendingAdjustments, pendingAmendments, recommendPip, combined, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [panel, setPanel] = useState<{ item: string; kind: 'actual' | 'amend' | 'edit' } | null>(null);
    const [open, setOpen] = useState<'item' | 'close' | 'transfer' | 'checkin' | 'evidence' | 'pip' | 'idp' | 'adjust' | null>(null);
    const status = agreement.status;
    const result = agreement.result;
    const toggle = (key: typeof open) => setOpen(open === key ? null : key);

    async function workflow(action: 'submit' | 'approve' | 'return') {
        const needsReason = action === 'return';
        const title = t(action === 'submit' ? 'performance.actions.submitToEmployee' : `performance.actions.${action}`);
        const { confirmed, reason } = await confirm({ title, description: employeeName(agreement.employee, locale), confirmLabel: title, cancelLabel: t('performance.actions.cancel'), requireReason: needsReason, reasonLabel: t('performance.fields.reason'), variant: needsReason ? 'danger' : undefined });
        if (confirmed) router.post(route('performance.agreements.workflow', [agreement.id, action]), needsReason ? { reason } : {}, { preserveScroll: true });
    }

    async function simple(url: string, title: string) {
        const { confirmed } = await confirm({ title, description: employeeName(agreement.employee, locale), confirmLabel: title, cancelLabel: t('performance.actions.cancel') });
        if (confirmed) router.post(url, {}, { preserveScroll: true });
    }

    async function removeItem(item: AgreementItem) {
        const { confirmed } = await confirm({ title: t('performance.actions.remove'), description: item.kpi.code, confirmLabel: t('performance.actions.remove'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.items.destroy', item.id), { preserveScroll: true });
    }

    const itemActions = (item: AgreementItem) => (
        <>
            {can.edit && <button type="button" className={smallBtn} onClick={() => setPanel({ item: item.id, kind: 'edit' })}>{t('performance.actions.edit')}</button>}
            {can.edit && !item.is_mandatory && <button type="button" className={smallBtn} onClick={() => removeItem(item)}>{t('performance.actions.remove')}</button>}
            {can.enterActual && LIVE.includes(status) && MANUAL_SOURCES.includes(item.data_source) && <button type="button" className={smallBtn} onClick={() => setPanel({ item: item.id, kind: 'actual' })}>{t('performance.agreements.recordActual')}</button>}
            {can.manage && LIVE.includes(status) && SYNC_SOURCES.includes(item.data_source) && <button type="button" className={smallBtn} onClick={() => router.post(route('performance.items.sync', item.id), { period_start: agreement.effective_from, period_end: agreement.effective_to }, { preserveScroll: true })}>{t('performance.actions.sync')}</button>}
            {can.amend && ['AGREED', 'ACTIVE'].includes(status) && <button type="button" className={smallBtn} onClick={() => setPanel({ item: item.id, kind: 'amend' })}>{t('performance.actions.requestAmendment')}</button>}
        </>
    );

    const itemExtra = (item: AgreementItem) => (
        <>
            <ActualsList actuals={item.actuals} canVerify={can.verify} />
            {panel?.item === item.id && panel.kind === 'actual' && <ActualForm url={route('performance.items.actuals.store', item.id)} period={[agreement.effective_from, agreement.effective_to]} milestones={item.kpi.direction === 'MILESTONE' ? item.kpi.milestones : null} onDone={() => setPanel(null)} />}
            {panel?.item === item.id && panel.kind === 'amend' && <AmendmentForm url={route('performance.items.amendments.store', item.id)} onDone={() => setPanel(null)} />}
            {panel?.item === item.id && panel.kind === 'edit' && <ItemForm agreementId={agreement.id} item={item} planTargets={planTargets} onDone={() => setPanel(null)} />}
        </>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={`${employeeName(agreement.employee, locale)} (${agreement.employee.number ?? ''})`} description={agreementHeading(agreement, locale)} backHref={route('performance.agreements.index')}
            actions={<div className="flex flex-wrap gap-2">
                {can.edit && <button type="button" className={primaryBtn} onClick={() => workflow('submit')}>{t('performance.actions.submitToEmployee')}</button>}
                {can.approve && <button type="button" className={secondaryBtn} onClick={() => workflow('return')}>{t('performance.actions.return')}</button>}
                {can.approve && <button type="button" className={primaryBtn} onClick={() => workflow('approve')}>{t('performance.actions.approve')}</button>}
                {can.manage && ['ACTIVE', 'UNDER_REVIEW', 'CLOSED'].includes(status) && <button type="button" className={secondaryBtn} onClick={() => simple(route('performance.agreements.calculate', agreement.id), t('performance.actions.calculate'))}>{t('performance.actions.calculate')}</button>}
            </div>} />}>
            <Head title={employeeName(agreement.employee, locale)} />
            <div className={pageCls}>
                <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-slate-400">
                    <Pill group="agreement" value={status} />
                    <span><LocalizedDateDisplay value={agreement.effective_from} /> – <LocalizedDateDisplay value={agreement.effective_to} /></span>
                    {agreement.version > 1 && <span>v{agreement.version}</span>}
                    {agreement.is_temporary && <span>{t('performance.fields.temporary')}</span>}
                    {agreement.manager && <span>{t('performance.fields.manager')}: {agreement.manager}</span>}
                    {agreement.plan && <Link className="text-[color:var(--color-primary)] hover:underline" href={route('performance.plans.show', agreement.plan.id)}>{t('performance.agreements.positionPlan')}: {agreement.plan.title} (v{agreement.plan.version})</Link>}
                    {can.manage && LIVE.includes(status) && (
                        <span className="flex gap-2">
                            <button type="button" className={smallBtn} onClick={() => toggle('close')}>{t('performance.actions.close')}</button>
                            <button type="button" className={smallBtn} onClick={() => toggle('transfer')}>{t('performance.actions.transfer')}</button>
                        </span>
                    )}
                </div>
                {agreement.return_reason && status === 'RETURNED' && <Problems title={t('performance.enums.agreement.RETURNED')} problems={[agreement.return_reason]} />}
                <Problems title={t('performance.agreements.validation')} problems={validation} />
                {open === 'close' && <CloseForm agreementId={agreement.id} onDone={() => setOpen(null)} />}
                {open === 'transfer' && <TransferForm agreementId={agreement.id} employeeNumber={agreement.employee.number ?? ''} onDone={() => setOpen(null)} />}

                <Section title={t('performance.agreements.items')} actions={can.edit && planTargets.length > 0 && <button type="button" className={smallBtn} onClick={() => toggle('item')}>{t('performance.agreements.addItem')}</button>}>
                    {open === 'item' && <ItemForm agreementId={agreement.id} planTargets={planTargets} onDone={() => setOpen(null)} />}
                    <ItemsTable items={agreement.items} actions={can.manage ? itemActions : undefined} extra={itemExtra} />
                </Section>

                {pendingAmendments.length > 0 && (
                    <Section title={t('performance.plans.amendments')}>
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                            {pendingAmendments.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <p className="text-xs font-medium">{agreement.items.find((i) => i.id === a.subject_id)?.kpi.code}</p>
                                        <AmendmentDiff original={a.original_values} proposed={a.proposed_values} />
                                        <p className="text-xs text-gray-500">{a.reason} · <LocalizedDateDisplay value={a.effective_date} /></p>
                                    </div>
                                    {can.manage && (
                                        <div className="flex gap-2">
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.item-amendments.decide', a.id), { approve: false }, { preserveScroll: true })}>{t('performance.actions.decline')}</button>
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.item-amendments.decide', a.id), { approve: true }, { preserveScroll: true })}>{t('performance.actions.approve')}</button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title={t('performance.agreements.checkins')} actions={can.checkin && LIVE.includes(status) && <button type="button" className={smallBtn} onClick={() => toggle('checkin')}>{t('performance.agreements.addCheckin')}</button>}>
                        {open === 'checkin' && <CheckinForm agreementId={agreement.id} onDone={() => setOpen(null)} />}
                        <CheckinList checkins={agreement.checkins} />
                    </Section>
                    <Section title={t('performance.fields.evidence')} actions={can.manage && <button type="button" className={smallBtn} onClick={() => toggle('evidence')}>{t('performance.actions.upload')}</button>}>
                        {open === 'evidence' && <div className="mb-3"><EvidenceForm url={route('performance.agreements.evidence.store', agreement.id)} items={agreement.items.map((i) => ({ id: i.id, label: i.kpi.code }))} onDone={() => setOpen(null)} /></div>}
                        <EvidenceList evidence={agreement.evidence} canVerify={can.verify} />
                    </Section>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {(['MID_YEAR', 'YEAR_END'] as const).map((type) => (
                        <Section key={type} title={type === 'MID_YEAR' ? t('performance.agreements.midYear') : t('performance.agreements.yearEnd')}>
                            <ReviewSummary review={agreement.reviews[type]} />
                            {can.review && ['UNDER_REVIEW', 'ACTIVE'].includes(status) && agreement.reviews[type]?.status !== 'COMPLETED' && (
                                <ReviewForm agreement={agreement} type={type} />
                            )}
                        </Section>
                    ))}
                </div>

                {agreement.competencies.length > 0 && (
                    <Section title={t('performance.agreements.competencies')}>
                        <ul className="grid gap-2 text-sm sm:grid-cols-2">
                            {agreement.competencies.map((c) => (
                                <li key={c.competency_id} className="flex justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-slate-800/60">
                                    <span>{c.code} — {(locale === 'am' && c.name_am) || c.name_en} <span className="text-xs text-gray-500">({formatScore(c.weight)})</span></span>
                                    <span className="text-xs tabular-nums">{t('performance.fields.selfRating')}: {c.self_rating ?? '—'} · {t('performance.fields.managerRating')}: {c.manager_rating ?? '—'}</span>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section title={t('performance.agreements.result')} actions={result && <div className="flex flex-wrap gap-2">
                    {can.finalize && result.status === 'CALCULATED' && <button type="button" className={smallBtn} onClick={() => simple(route('performance.results.action', [result.id, 'finalize']), t('performance.actions.finalize'))}>{t('performance.actions.finalize')}</button>}
                    {can.finalize && result.status === 'PENDING_RELEASE' && <button type="button" className={smallBtn} onClick={() => simple(route('performance.results.action', [result.id, 'release']), t('performance.actions.release'))}>{t('performance.actions.release')}</button>}
                    {can.adjust && ['CALCULATED', 'PENDING_CALIBRATION'].includes(result.status) && <button type="button" className={smallBtn} onClick={() => toggle('adjust')}>{t('performance.actions.requestAdjustment')}</button>}
                </div>}>
                    {recommendPip && <p className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">{t('performance.agreements.pipRecommended')}</p>}
                    {open === 'adjust' && result && <AdjustmentForm resultId={result.id} onDone={() => setOpen(null)} />}
                    {pendingAdjustments.length > 0 && (
                        <ul className="mb-3 space-y-2 text-sm">
                            {pendingAdjustments.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 p-2 dark:bg-slate-800/60">
                                    <span>{formatScore(a.original_score)} → {formatScore(a.adjusted_score)} · {a.reason} <Pill group="approval" value="PENDING" /></span>
                                    {can.finalize && (
                                        <span className="flex gap-2">
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.adjustments.decide', a.id), { approve: false }, { preserveScroll: true })}>{t('performance.actions.decline')}</button>
                                            <button type="button" className={smallBtn} onClick={() => router.post(route('performance.adjustments.decide', a.id), { approve: true }, { preserveScroll: true })}>{t('performance.actions.approve')}</button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <ResultPanel result={result} hidden={agreement.result_hidden} />
                    {combined.parts.length > 1 && (
                        <p className="mt-3 text-sm">{t('performance.agreements.combined')}: <span className="font-semibold tabular-nums">{formatScore(combined.score)}</span> <span className="font-mono text-xs text-gray-500">{combined.formula}</span></p>
                    )}
                </Section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title={t('performance.agreements.pips')} actions={can.manage && <button type="button" className={smallBtn} onClick={() => toggle('pip')}>{t('performance.actions.add')}</button>}>
                        {open === 'pip' && <PipForm agreementId={agreement.id} onDone={() => setOpen(null)} />}
                        {agreement.improvement_plans.length === 0 ? <p className="text-sm text-gray-500">—</p> : (
                            <ul className="space-y-2 text-sm">
                                {agreement.improvement_plans.map((p) => (
                                    <li key={p.id} className="rounded-lg bg-gray-50 p-2 dark:bg-slate-800/60">
                                        <p>{p.identified_gap}</p>
                                        <p className="text-xs text-gray-500">{p.required_improvement} · <LocalizedDateDisplay value={p.start_date} /> – <LocalizedDateDisplay value={p.end_date} /> · {label('development', p.status)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                    <Section title={t('performance.agreements.idps')} actions={can.manage && <button type="button" className={smallBtn} onClick={() => toggle('idp')}>{t('performance.actions.add')}</button>}>
                        {open === 'idp' && <div className="mb-3"><DevelopmentPlanForm url={route('performance.agreements.idps.store', agreement.id)} competencies={agreement.competencies} onDone={() => setOpen(null)} /></div>}
                        <DevelopmentPlans plans={agreement.development_plans} />
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function ItemForm({ agreementId, item, planTargets, onDone }: { agreementId: string; item?: AgreementItem; planTargets: Props['planTargets']; onDone: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({
        kpi_id: item?.kpi.id ?? '', position_target_id: '', objective_id: '', expected_output: item?.expected_output ?? '', weight: item?.weight ?? '',
        target_value: item?.target_value ?? '', target_numerator: item?.target_numerator ?? '', target_denominator: item?.target_denominator ?? '', baseline_value: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function pickTarget(id: string) {
        const target = planTargets.find((p) => p.id === id);
        form.setData({ ...form.data, position_target_id: id, kpi_id: target?.kpi_id ?? '', objective_id: target?.objective_id ?? '', target_value: target?.target_value ?? '' });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        // Editing changes the wording, weight and target only; the KPI and its plan link stay fixed.
        form.transform((data) => item
            ? nullify({ expected_output: data.expected_output, weight: data.weight, target_value: data.target_value, target_numerator: data.target_numerator, target_denominator: data.target_denominator })
            : nullify(data));
        const options = { preserveScroll: true, onSuccess: onDone };
        if (item) form.put(route('performance.items.update', item.id), options);
        else form.post(route('performance.agreements.items.store', agreementId), options);
    }

    return (
        <form onSubmit={submit} className="my-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-4 dark:bg-slate-800/50">
            {!item && (
                <Field label={t('performance.fields.kpi')} error={errors.kpi_id ?? errors.position_target_id} className="sm:col-span-2">
                    <select className={inputCls} value={form.data.position_target_id} onChange={(e) => pickTarget(e.target.value)} required>
                        <option value="">—</option>
                        {planTargets.map((p) => <option key={p.id} value={p.id}>{p.kpi_code} — {(locale === 'am' && p.kpi_name_am) || p.kpi_name_en} ({formatScore(p.target_value)})</option>)}
                    </select>
                </Field>
            )}
            <Field label={t('performance.fields.weight')} error={errors.weight}><input className={inputCls} inputMode="decimal" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} required /></Field>
            <Field label={t('performance.fields.target')} error={errors.target_value}><input className={inputCls} inputMode="decimal" value={form.data.target_value} onChange={(e) => form.setData('target_value', e.target.value)} /></Field>
            <Field label={t('performance.fields.description')} error={errors.expected_output} className="sm:col-span-2 lg:col-span-4"><input className={inputCls} value={form.data.expected_output} onChange={(e) => form.setData('expected_output', e.target.value)} required /></Field>
            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

function CloseForm({ agreementId, onDone }: { agreementId: string; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({ end_date: '', reason: '' });
    return (
        <Section title={t('performance.actions.close')}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(route('performance.agreements.workflow', [agreementId, 'close']), { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 sm:grid-cols-3">
                <Field label={t('performance.fields.endDate')} error={form.errors.end_date}><LocalizedDatePicker value={form.data.end_date} onChange={(v) => form.setData('end_date', v)} required /></Field>
                <Field label={t('performance.fields.reason')} error={form.errors.reason} className="sm:col-span-2"><input className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></Field>
                <div className="flex justify-end gap-2 sm:col-span-3">
                    <button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.close')}</button>
                </div>
            </form>
        </Section>
    );
}

type AssignmentHit = { assignment_id: string; employee_id: string; is_current: boolean; name: string | null; name_en: string | null; number: string | null; organization: string | null; unit: string | null; position: string | null; organization_am?: string | null; unit_am?: string | null; position_am?: string | null; effective_from: string | null };

function TransferForm({ agreementId, employeeNumber, onDone }: { agreementId: string; employeeNumber: string; onDone: () => void }) {
    const { t, locale } = useLocale();
    const [picked, setPicked] = useState('');
    const form = useForm({ employee_assignment_id: '', is_temporary: false });
    return (
        <Section title={t('performance.actions.transfer')} description={t('performance.agreements.transferHelp')}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(route('performance.agreements.transfer', agreementId)); }} className="grid gap-3 sm:grid-cols-3">
                <Field label={`${t('performance.fields.position')} (${employeeNumber})`} error={form.errors.employee_assignment_id} className="sm:col-span-2">
                    <Lookup<AssignmentHit> url={route('performance.lookups.employees')} minChars={2} value={form.data.employee_assignment_id} display={picked} keyOf={(a) => a.assignment_id}
                        render={(a) => `${employeeName(a, locale)} (${a.number ?? ''}) · ${assignmentPlace(a, locale)}`}
                        onChange={(id, a) => { form.setData('employee_assignment_id', id); setPicked(a ? `${(locale === 'am' && a.position_am) || a.position || ''} · ${(locale === 'am' && a.unit_am) || a.unit || ''}` : ''); }} placeholder={employeeNumber} />
                </Field>
                <label className="flex min-h-10 items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.data.is_temporary} onChange={(e) => form.setData('is_temporary', e.target.checked)} />{t('performance.fields.temporary')}</label>
                <div className="flex justify-end gap-2 sm:col-span-3">
                    <button type="button" className={secondaryBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                    <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.employee_assignment_id}>{t('performance.actions.transfer')}</button>
                </div>
            </form>
        </Section>
    );
}

function CheckinForm({ agreementId, onDone }: { agreementId: string; onDone: () => void }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const form = useForm({ checkin_date: new Date().toISOString().slice(0, 10), progress_status: 'ON_TRACK', manager_comment: '', manager_private_note: '', blockers: '', support_required: '', learning_needs: '', next_actions: '' });
    const area = (key: 'manager_comment' | 'manager_private_note' | 'blockers' | 'support_required' | 'learning_needs' | 'next_actions', caption: string) => (
        <Field label={caption} error={form.errors[key]}><textarea rows={2} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} /></Field>
    );
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.transform(nullify); form.post(route('performance.agreements.checkins.store', agreementId), { preserveScroll: true, onSuccess: onDone }); }} className="mb-3 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-slate-800/50">
            <Field label={t('performance.fields.checkinDate')} error={form.errors.checkin_date}><LocalizedDatePicker value={form.data.checkin_date} onChange={(v) => form.setData('checkin_date', v)} required /></Field>
            <Field label={t('performance.fields.progress')} error={form.errors.progress_status}>
                <select className={inputCls} value={form.data.progress_status} onChange={(e) => form.setData('progress_status', e.target.value)}>
                    {['ON_TRACK', 'AT_RISK', 'OFF_TRACK', 'NOT_REPORTED'].map((s) => <option key={s} value={s}>{label('health', s)}</option>)}
                </select>
            </Field>
            {area('manager_comment', t('performance.fields.managerComment'))}
            {area('manager_private_note', t('performance.fields.privateNote'))}
            {area('blockers', t('performance.fields.blockers'))}
            {area('support_required', t('performance.fields.supportRequired'))}
            {area('learning_needs', t('performance.fields.learningNeeds'))}
            {area('next_actions', t('performance.fields.nextActions'))}
            <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

function ReviewForm({ agreement, type }: { agreement: AgreementView; type: 'MID_YEAR' | 'YEAR_END' }) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const slug = type.toLowerCase();
    const levels = ratingLevels(agreement.competency_scale_max);
    const form = useForm<{ manager_comment: string; manager_private_note: string; improvement_actions: string; at_risk_item_ids: string[]; ratings: Record<string, number> }>({
        manager_comment: '', manager_private_note: '', improvement_actions: '', at_risk_item_ids: [],
        ratings: Object.fromEntries(agreement.competencies.filter((c) => c.manager_rating !== null).map((c) => [c.competency_id, c.manager_rating as number])),
    });
    const errors = form.errors as Record<string, string | undefined>;

    async function returnReview() {
        const { confirmed, reason } = await confirm({ title: t('performance.actions.return'), confirmLabel: t('performance.actions.return'), cancelLabel: t('performance.actions.cancel'), requireReason: true, reasonLabel: t('performance.fields.reason') });
        if (confirmed) router.post(route('performance.agreements.reviews.return', [agreement.id, slug]), { reason }, { preserveScroll: true });
    }

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('performance.agreements.reviews.complete', [agreement.id, slug]), { preserveScroll: true }); }} className="mt-4 space-y-3 border-t border-gray-100 pt-3 dark:border-slate-800">
            <Field label={t('performance.fields.managerComment')} error={errors.manager_comment}><textarea rows={3} className={inputCls} value={form.data.manager_comment} onChange={(e) => form.setData('manager_comment', e.target.value)} /></Field>
            <Field label={t('performance.fields.improvementActions')} error={errors.improvement_actions}><textarea rows={2} className={inputCls} value={form.data.improvement_actions} onChange={(e) => form.setData('improvement_actions', e.target.value)} /></Field>
            <Field label={t('performance.fields.privateNote')} error={errors.manager_private_note}><textarea rows={2} className={inputCls} value={form.data.manager_private_note} onChange={(e) => form.setData('manager_private_note', e.target.value)} /></Field>
            <fieldset>
                <legend className="mb-1 text-xs font-medium text-gray-600 dark:text-slate-400">{t('performance.enums.health.AT_RISK')}</legend>
                <div className="flex flex-wrap gap-2">
                    {agreement.items.map((item) => (
                        <label key={item.id} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2 py-1 text-xs dark:border-slate-700">
                            <input type="checkbox" checked={form.data.at_risk_item_ids.includes(item.id)} onChange={(e) => form.setData('at_risk_item_ids', e.target.checked ? [...form.data.at_risk_item_ids, item.id] : form.data.at_risk_item_ids.filter((id) => id !== item.id))} />
                            {item.kpi.code}
                        </label>
                    ))}
                </div>
            </fieldset>
            {/* Competency ratings are part of the year-end result only. */}
            {type === 'YEAR_END' && agreement.competencies.length > 0 && (
                <fieldset className="grid gap-2 sm:grid-cols-2">
                    <legend className="mb-1 text-xs font-medium text-gray-600 dark:text-slate-400">{t('performance.fields.managerRating')} (1–{levels.length})</legend>
                    {agreement.competencies.map((c) => (
                        <label key={c.competency_id} className="flex items-center justify-between gap-2 text-xs">
                            <span>{c.code} — {(locale === 'am' && c.name_am) || c.name_en}{c.self_rating !== null && <span className="text-gray-500"> ({t('performance.fields.selfRating')}: {c.self_rating})</span>}</span>
                            <select className="rounded-lg border border-gray-300 px-2 py-1 dark:border-slate-700 dark:bg-slate-950" value={form.data.ratings[c.competency_id] ?? ''} onChange={(e) => form.setData('ratings', { ...form.data.ratings, [c.competency_id]: Number(e.target.value) })}>
                                <option value="">—</option>
                                {levels.map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                            {errors[`ratings.${c.competency_id}`] && <span className="text-red-700">{errors[`ratings.${c.competency_id}`]}</span>}
                        </label>
                    ))}
                </fieldset>
            )}
            {(errors.review || errors.status) && <p className="text-xs text-red-700">{errors.review ?? errors.status}</p>}
            <div className="flex justify-end gap-2">
                {agreement.reviews[type]?.status === 'EMPLOYEE_SUBMITTED' && <button type="button" className={secondaryBtn} onClick={returnReview}>{t('performance.actions.return')}</button>}
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.complete')}</button>
            </div>
        </form>
    );
}

function AdjustmentForm({ resultId, onDone }: { resultId: string; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({ adjusted_score: '', reason: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('performance.results.adjustments.store', resultId), { preserveScroll: true, onSuccess: onDone }); }} className="mb-3 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-3 dark:bg-slate-800/50">
            <Field label={t('performance.fields.score0to200')} error={form.errors.adjusted_score}><input className={inputCls} inputMode="decimal" value={form.data.adjusted_score} onChange={(e) => form.setData('adjusted_score', e.target.value)} required /></Field>
            <Field label={t('performance.fields.reason')} error={form.errors.reason} className="sm:col-span-2"><input className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></Field>
            <div className="flex justify-end gap-2 sm:col-span-3">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.requestAdjustment')}</button>
            </div>
        </form>
    );
}

function PipForm({ agreementId, onDone }: { agreementId: string; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({ identified_gap: '', required_improvement: '', support_action: '', training: '', start_date: '', end_date: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.transform(nullify); form.post(route('performance.agreements.pips.store', agreementId), { preserveScroll: true, onSuccess: onDone }); }} className="mb-3 grid gap-2 sm:grid-cols-2">
            <Field label={t('performance.fields.identifiedGap')} error={form.errors.identified_gap} className="sm:col-span-2"><textarea rows={2} className={inputCls} value={form.data.identified_gap} onChange={(e) => form.setData('identified_gap', e.target.value)} required /></Field>
            <Field label={t('performance.fields.requiredImprovement')} error={form.errors.required_improvement} className="sm:col-span-2"><textarea rows={2} className={inputCls} value={form.data.required_improvement} onChange={(e) => form.setData('required_improvement', e.target.value)} required /></Field>
            <Field label={t('performance.fields.supportAction')} error={form.errors.support_action}><input className={inputCls} value={form.data.support_action} onChange={(e) => form.setData('support_action', e.target.value)} /></Field>
            <Field label={t('performance.fields.training')} error={form.errors.training}><input className={inputCls} value={form.data.training} onChange={(e) => form.setData('training', e.target.value)} /></Field>
            <Field label={t('performance.fields.startDate')} error={form.errors.start_date}><LocalizedDatePicker value={form.data.start_date} onChange={(v) => form.setData('start_date', v)} required /></Field>
            <Field label={t('performance.fields.endDate')} error={form.errors.end_date}><LocalizedDatePicker value={form.data.end_date} onChange={(v) => form.setData('end_date', v)} required /></Field>
            <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}
