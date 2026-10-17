import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { dangerBtn, inputCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import { EvidenceForm, FinalStatementForm, ResponseForm } from '@/Components/discipline/forms';
import {
    AppealSummary, CaseStatusBadge, ChargeList, DeadlineList, Detail, EvidenceList, Field, Section, SuspensionList, TextArea, employeeLabel, penaltyLabel, useDates,
    type Abilities, type CaseDetail, type EmployeeRef,
} from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Member = { id: string; employee: EmployeeRef; role: string; selection_source: string | null; is_active: boolean; recused_at: string | null; recusal_reason: string | null };
type Recommendation = {
    id: string; version: number; status: string; finding: string; penalty: string | null; penalty_amount: number | null; penalty_unit: string | null;
    gravity_assessment: string | null; conduct_assessment: string | null; prior_record_assessment: string | null; reasoning: string;
    members_present: number | null; votes_for: number | null; votes_against: number | null; tie_broken_by_chair: boolean; adopted_at: string | null;
    dissents: { member_id: string; reason: string }[];
};
type StaffCase = CaseDetail & {
    committee: { id: string; name: string } | null;
    members: Member[];
    recommendations: Recommendation[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string | null }[];
    source_type: string | null;
};
type Penalty = { value: string; severity: string; unit: string | null; max: number | null };
type Props = {
    disciplinaryCase: StaffCase;
    abilities: Abilities;
    committees: { id: string; name: string; problems: string[] }[];
    votingMembers: { id: string; employee: EmployeeRef; role: string }[];
    options: { penalties: Penalty[]; objectionGrounds: string[]; headActions: string[]; sides: string[] };
};

/**
 * A date in the interface's calendar (Ethiopian in Amharic) and a clock time
 * (Ethiopian hours in Amharic). The value is the Gregorian "Y-m-d HH:mm" the
 * server parses.
 */
function LocalizedDateTime({ id, value, onChange, required }: { id: string; value: string; onChange: (v: string) => void; required?: boolean }): JSX.Element {
    const [day = '', time = ''] = value.split(' ');
    return (
        <div className="flex flex-wrap items-center gap-2">
            <LocalizedDatePicker id={id} value={day} onChange={(d) => onChange(d ? `${d} ${time || '09:00'}` : '')} required={required} />
            <LocalizedTimePicker value={time || '09:00'} onChange={(tm) => onChange(day ? `${day} ${tm}` : '')} />
        </div>
    );
}

/** URL of a discipline.cases.* action; names are fixed literals at every call site. */
const go = (name: string, id: string, extra?: string): string =>
    (route as unknown as (routeName: string, params: unknown) => string)(`discipline.cases.${name}`, extra ? [id, extra] : id);

export default function DisciplineShow({ disciplinaryCase: c, abilities: can, committees, votingMembers, options }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { date, dateTime } = useDates();
    const post = (name: string, data: Record<string, unknown> = {}) => router.post(go(name, c.id), data as never, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('discipline.title')} · ${c.case_number ?? ''}`} description={t('discipline.notice')}
            actions={<Link href={route('discipline.cases.index')} className={secondaryBtn}>{t('discipline.title')}</Link>} />}>
            <Head title={c.case_number ?? t('discipline.title')} />
            <div className="grid gap-3 lg:grid-cols-3">
                <div className="space-y-3 lg:col-span-2">
                    <Section title={t('discipline.sections.overview')} actions={<CaseStatusBadge status={c.status} />}>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Detail label={t('discipline.accused')}>{employeeLabel(c.accused, locale)}</Detail>
                            <Detail label={t('discipline.organization')}>{c.organization?.name ?? '—'}</Detail>
                            <Detail label={t('discipline.unit')}>{c.unit ?? '—'}</Detail>
                            <Detail label={t('discipline.position')}>{c.position ?? '—'}</Detail>
                            <Detail label={t('discipline.trackLabel')}>{t(`discipline.tracks.${c.track}`)}</Detail>
                            {c.legacy_regulation && <Detail label={t('discipline.governingRegulation')}>{t('discipline.regulationLegacy')}</Detail>}
                            <Detail label={t('discipline.severityLabel')}>{t(`discipline.severities.${c.severity}`)}</Detail>
                            <Detail label={t('discipline.chargedAt')}>{dateTime(c.charged_at)}</Detail>
                            <Detail label={t('discipline.panel.committee')}>{c.committee?.name ?? '—'}</Detail>
                        </dl>
                        {c.summary && <p className="mt-3 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">{c.summary}</p>}
                    </Section>

                    <Section title={t('discipline.sections.charges')}>
                        {c.charges.length > 0 ? <ChargeList charges={c.charges} /> : <p className="text-sm text-gray-500">—</p>}
                        {can.addCharge && <div className="mt-4"><AddChargeForm caseId={c.id} /></div>}
                        {can.fileCharge && (
                            <div className="mt-4 rounded-control border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                                <p className="mb-2 text-amber-900 dark:text-amber-200">{t('discipline.actions.fileChargeHelp')}</p>
                                <button type="button" className={primaryBtn} onClick={() => post('file')}>{t('discipline.actions.fileCharge')}</button>
                            </div>
                        )}
                    </Section>

                    {(can.assignPanel || c.members.length > 0) && (
                        <Section title={t('discipline.sections.panel')}>
                            {c.members.length > 0 && (
                                <ul className="mb-3 divide-y divide-gray-100 dark:divide-slate-800">
                                    {c.members.map((m) => <MemberRow key={m.id} member={m} caseId={c.id} canRecuse={can.recuse} />)}
                                </ul>
                            )}
                            {can.assignPanel && <PanelForm caseId={c.id} track={c.track} committees={committees} />}
                        </Section>
                    )}

                    {c.track === 'formal' && c.status !== 'draft' && (
                        <Section title={t('discipline.sections.summons')}>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Detail label={t('discipline.summons.servedAt')}>{dateTime(c.summons.served_at)}</Detail>
                                <Detail label={t('discipline.summons.method')}>{c.summons.method ? t(`discipline.deliveryMethods.${c.summons.method}`) : '—'}</Detail>
                                <Detail label={t('discipline.summons.hearingAt')}>{dateTime(c.summons.hearing_at)}</Detail>
                                <Detail label={t('discipline.summons.hearingLocation')}>{c.summons.hearing_location ?? '—'}</Detail>
                            </dl>
                            {c.in_absentia && <p className="mt-2 text-sm text-amber-800 dark:text-amber-300">{t('discipline.summons.inAbsentia')}</p>}
                            {can.serveSummons && <div className="mt-4"><SummonsForm caseId={c.id} /></div>}
                        </Section>
                    )}

                    {c.status !== 'draft' && (
                        <Section title={t('discipline.sections.response')}>
                            {c.responses.map((r) => (
                                <div key={r.id} className="mb-2 rounded-control border border-gray-100 p-3 text-sm dark:border-slate-800">
                                    <p className="text-xs text-gray-500 dark:text-slate-400">#{r.version} · {dateTime(r.received_at)} · {r.acknowledged_name}</p>
                                    {r.pleas.length > 0 && (
                                        <ul className="mt-1 space-y-0.5">
                                            {r.pleas.map((p) => <li key={p.charge_id}>{p.sequence}. {t('discipline.charge.plea')}: <strong>{t(`discipline.pleas.${p.plea}`)}</strong>{p.statement ? ` — ${p.statement}` : ''}</li>)}
                                        </ul>
                                    )}
                                    {r.objection_ground && (
                                        <p className="mt-1">{t('discipline.response.objectionDecision')}: <strong>{t(`discipline.objectionGrounds.${r.objection_ground}`)}</strong>
                                            {r.objection_text ? ` — ${r.objection_text}` : ''}{r.objection_decision ? ` (${t(`discipline.actions.${r.objection_decision === 'accepted' ? 'accept' : 'reject'}`)})` : ''}</p>
                                    )}
                                    {r.defense_evidence_list && <p className="mt-1 whitespace-pre-line text-gray-700 dark:text-slate-300">{r.defense_evidence_list}</p>}
                                </div>
                            ))}
                            {can.decideObjection && <ObjectionForm caseId={c.id} />}
                            {can.recordResponse && <div className="mt-3"><ResponseForm action={go('response', c.id)} charges={c.charges} objectionGrounds={options.objectionGrounds} /></div>}
                            {can.proceedInAbsentia && <ReasonAction caseId={c.id} name="absentia" label={t('discipline.actions.proceedInAbsentia')} />}
                        </Section>
                    )}

                    <Section title={t('discipline.sections.evidence')}>
                        <EvidenceList caseId={c.id} evidence={c.evidence} routeName="discipline.cases.evidence.download" />
                        {can.addEvidence && <div className="mt-4"><EvidenceForm action={go('evidence.store', c.id)} sides={options.sides} charges={c.charges} /></div>}
                    </Section>

                    {(c.witnesses.length > 0 || can.recordWitness) && (
                        <Section title={t('discipline.sections.witnesses')}>
                            <ul className="space-y-2">
                                {c.witnesses.map((w) => (
                                    <li key={w.id} className="rounded-control border border-gray-100 p-3 text-sm dark:border-slate-800">
                                        <p className="font-medium">{w.name} <span className="text-xs font-normal text-gray-500">· {t(`discipline.sides.${w.called_by}`)} · {dateTime(w.heard_at)}</span></p>
                                        {w.relationship_disclosure && <p className="text-xs text-gray-500">{t('discipline.witness.relationship')}: {w.relationship_disclosure}</p>}
                                        {w.statement && <p className="mt-1 whitespace-pre-line">{w.statement}</p>}
                                        {w.cross_examination && <p className="mt-1 whitespace-pre-line text-gray-600 dark:text-slate-400">{t('discipline.witness.crossExamination')}: {w.cross_examination}</p>}
                                    </li>
                                ))}
                            </ul>
                            {can.recordWitness && <div className="mt-4"><WitnessForm caseId={c.id} sides={options.sides} /></div>}
                        </Section>
                    )}

                    {(c.final_statement.at || can.finalStatement) && (
                        <Section title={t('discipline.sections.finalStatement')}>
                            {c.final_statement.at && (
                                <p className="whitespace-pre-line text-sm">{c.final_statement.declined ? t('discipline.actions.declined') : c.final_statement.text} <span className="text-xs text-gray-500">· {dateTime(c.final_statement.at)}</span></p>
                            )}
                            {can.finalStatement && <div className="mt-3"><FinalStatementForm action={go('final-statement', c.id)} /></div>}
                        </Section>
                    )}

                    {(c.recommendations.length > 0 || can.recommend) && (
                        <Section title={t('discipline.sections.recommendation')}>
                            {c.recommendations.map((r) => <RecommendationCard key={r.id} r={r} members={c.members} />)}
                            {can.recommend && <RecommendationForm caseId={c.id} track={c.track} penalties={options.penalties} votingMembers={votingMembers} />}
                        </Section>
                    )}

                    {(c.decisions.length > 0 || can.decide) && (
                        <Section title={t('discipline.sections.decision')}>
                            {c.decisions.map((d) => (
                                <div key={d.id} className="mb-2 rounded-control border border-gray-100 p-3 text-sm dark:border-slate-800">
                                    <p className="font-medium">{t(`discipline.headActions.${d.action}`)} · {dateTime(d.decided_at)}</p>
                                    {d.finding && <p>{t(`discipline.findings.${d.finding}`)} — {penaltyLabel(t, d.penalty, d.penalty_amount, d.penalty_unit)}</p>}
                                    <p className="mt-1 whitespace-pre-line text-gray-700 dark:text-slate-300">{d.reason}</p>
                                    <p className="mt-1 text-xs text-gray-500">{t('discipline.decision.deliveredAt')}: {d.delivered_at ? `${dateTime(d.delivered_at)} (${t(`discipline.deliveryMethods.${d.delivery_method}`)})` : t('discipline.decision.notDelivered')}
                                        {d.effective_on ? ` · ${t('discipline.decision.effectiveOn')}: ${date(d.effective_on)}` : ''}</p>
                                    {d.appeal && <AppealSummary appeal={d.appeal} stayed={Boolean(d.execution_stayed)} />}
                                </div>
                            ))}
                            {can.decide && <DecisionForm caseId={c.id} track={c.track} penalties={options.penalties} actions={options.headActions} />}
                            {can.deliver && <DeliveryForm caseId={c.id} />}
                            <AppealActions caseId={c.id} can={can} />
                        </Section>
                    )}
                </div>

                <div className="space-y-3">
                    <Section title={t('discipline.sections.deadlines')}><DeadlineList deadlines={c.deadlines} /></Section>
                    {(c.suspensions.length > 0 || can.suspend) && (
                        <Section title={t('discipline.sections.suspension')}>
                            {c.suspensions.length > 0 && <SuspensionList suspensions={c.suspensions} />}
                            <SuspensionActions caseId={c.id} can={can} dueBackPay={c.suspensions.filter((s) => s.back_pay_status === 'due').map((s) => s.id)} />
                        </Section>
                    )}
                    {can.extend && (
                        <Section title={t('discipline.sections.deadlines')}>
                            <button type="button" className={secondaryBtn} onClick={() => post('extend')}>{t('discipline.actions.extend')}</button>
                        </Section>
                    )}
                    {(can.discontinue || can.terminate || can.close) && (
                        <Section title={t('discipline.sections.end')}>
                            <div className="space-y-3">
                                {can.close && <button type="button" className={primaryBtn} onClick={() => post('close')}>{t('discipline.actions.close')}</button>}
                                {can.discontinue && <ReasonAction caseId={c.id} name="discontinue" label={t('discipline.actions.discontinue')} danger />}
                                {can.terminate && <ReasonAction caseId={c.id} name="terminate" label={t('discipline.actions.terminate')} danger />}
                            </div>
                        </Section>
                    )}
                    <Section title={t('discipline.sections.history')}>
                        <ol className="space-y-2 text-sm">
                            {c.history.map((h, i) => (
                                <li key={i}>
                                    <span className="font-medium">{t(`discipline.statuses.${h.to}`)}</span>
                                    <span className="block text-xs text-gray-500 dark:text-slate-400">{dateTime(h.at)} · {h.actor ?? '—'}</span>
                                    {h.reason && <span className="block text-xs text-gray-600 dark:text-slate-300">{h.reason}</span>}
                                </li>
                            ))}
                        </ol>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function AddChargeForm({ caseId }: { caseId: string }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ title: '', particulars: '', occurred_on: '', occurred_place: '', violated_provision: '', evidence_list: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(go('charges.store', caseId), { preserveScroll: true, onSuccess: () => form.reset() }); };

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            <Field id="ch-title" label={t('discipline.charge.title')} error={form.errors.title}><input id="ch-title" className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field>
            <Field id="ch-provision" label={t('discipline.charge.violatedProvision')} error={form.errors.violated_provision}><input id="ch-provision" className={inputCls} value={form.data.violated_provision} onChange={(e) => form.setData('violated_provision', e.target.value)} required /></Field>
            <Field id="ch-date" label={t('discipline.charge.occurredOn')} error={form.errors.occurred_on}><LocalizedDatePicker id="ch-date" value={form.data.occurred_on} onChange={(v) => form.setData('occurred_on', v)} required /></Field>
            <Field id="ch-place" label={t('discipline.charge.occurredPlace')} error={form.errors.occurred_place}><input id="ch-place" className={inputCls} value={form.data.occurred_place} onChange={(e) => form.setData('occurred_place', e.target.value)} required /></Field>
            <div className="sm:col-span-2"><Field id="ch-part" label={t('discipline.charge.particulars')} error={form.errors.particulars}><TextArea id="ch-part" value={form.data.particulars} onChange={(v) => form.setData('particulars', v)} /></Field></div>
            <div className="sm:col-span-2"><Field id="ch-ev" label={t('discipline.charge.evidenceList')} error={form.errors.evidence_list}><TextArea id="ch-ev" rows={2} value={form.data.evidence_list} onChange={(v) => form.setData('evidence_list', v)} /></Field></div>
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.charge.add')}</button></div>
        </form>
    );
}

function PanelForm({ caseId, track, committees }: { caseId: string; track: string; committees: Props['committees'] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ committee_id: '', investigators: '' });
    const chosen = committees.find((x) => x.id === form.data.committee_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ committee_id: d.committee_id || null, investigator_numbers: d.investigators.split(',').map((s) => s.trim()).filter(Boolean) }));
        form.post(go('panel', caseId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            {track === 'formal' ? (
                <Field id="committee" label={t('discipline.panel.committee')} error={form.errors.committee_id}>
                    <select id="committee" className={inputCls} value={form.data.committee_id} onChange={(e) => form.setData('committee_id', e.target.value)} required>
                        <option value="">{t('discipline.panel.chooseCommittee')}</option>
                        {committees.map((x) => <option key={x.id} value={x.id}>{x.name}{x.problems.length ? ' ⚠' : ''}</option>)}
                    </select>
                    {chosen && chosen.problems.length > 0 && (
                        <ul className="mt-2 list-disc ps-5 text-xs text-red-700 dark:text-red-400">
                            <li className="list-none -ms-5 font-medium">{t('discipline.panel.compositionProblems')}</li>
                            {chosen.problems.map((p) => <li key={p}>{t(`discipline.committeeRules.${p}`)}</li>)}
                        </ul>
                    )}
                </Field>
            ) : (
                <Field id="investigators" label={t('discipline.panel.investigators')} error={(form.errors as Record<string, string>).investigators}>
                    <input id="investigators" className={inputCls} value={form.data.investigators} onChange={(e) => form.setData('investigators', e.target.value)} required />
                </Field>
            )}
            <div><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.panel.assign')}</button></div>
        </form>
    );
}

function MemberRow({ member: m, caseId, canRecuse }: { member: Member; caseId: string; canRecuse: boolean }): JSX.Element {
    const { t, locale } = useLocale();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', replacement_employee_number: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, replacement_employee_number: d.replacement_employee_number || null }));
        form.post(go('members.recuse', caseId, m.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    const active = m.is_active && !m.recused_at;

    return (
        <li className="py-2 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span className={active ? '' : 'text-gray-400 line-through'}>{employeeLabel(m.employee, locale)}</span>
                    <span className="ms-2 text-xs text-gray-500">{t(`discipline.roles.${m.role}`)}{m.selection_source ? ` · ${t(`discipline.sources.${m.selection_source}`)}` : ''}</span>
                    {m.recused_at && <span className="block text-xs text-gray-500">{t('discipline.panel.recused')}: {m.recusal_reason}</span>}
                </div>
                {canRecuse && active && <button type="button" className={secondaryBtn} onClick={() => setOpen(!open)}>{t('discipline.panel.recuse')}</button>}
            </div>
            {open && (
                <form onSubmit={submit} className="mt-2 grid gap-2 sm:grid-cols-2">
                    <Field id={`rec-${m.id}`} label={t('discipline.panel.recusalReason')} error={form.errors.reason}><input id={`rec-${m.id}`} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></Field>
                    <Field id={`rep-${m.id}`} label={t('discipline.panel.replacement')} error={form.errors.replacement_employee_number}><input id={`rep-${m.id}`} className={inputCls} value={form.data.replacement_employee_number} onChange={(e) => form.setData('replacement_employee_number', e.target.value)} /></Field>
                    <div className="sm:col-span-2"><button type="submit" className={dangerBtn} disabled={form.processing}>{t('discipline.panel.recuse')}</button></div>
                </form>
            )}
        </li>
    );
}

function SummonsForm({ caseId }: { caseId: string }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ served_at: '', method: 'personal', hearing_at: '', hearing_location: '' });

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(go('summons', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-2">
            <Field id="sm-served" label={t('discipline.summons.servedAt')} error={form.errors.served_at}><LocalizedDateTime id="sm-served" value={form.data.served_at} onChange={(v) => form.setData('served_at', v)} required /></Field>
            <Field id="sm-method" label={t('discipline.summons.method')} error={form.errors.method}>
                <select id="sm-method" className={inputCls} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                    {['personal', 'family_member', 'posted'].map((m) => <option key={m} value={m}>{t(`discipline.deliveryMethods.${m}`)}</option>)}
                </select>
            </Field>
            <Field id="sm-hearing" label={t('discipline.summons.hearingAt')} error={form.errors.hearing_at}><LocalizedDateTime id="sm-hearing" value={form.data.hearing_at} onChange={(v) => form.setData('hearing_at', v)} required /></Field>
            <Field id="sm-place" label={t('discipline.summons.hearingLocation')} error={form.errors.hearing_location}><input id="sm-place" className={inputCls} value={form.data.hearing_location} onChange={(e) => form.setData('hearing_location', e.target.value)} required /></Field>
            <p className="text-xs text-gray-500 sm:col-span-2">{t('discipline.summons.noticeHelp')}</p>
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.serveSummons')}</button></div>
        </form>
    );
}

function ObjectionForm({ caseId }: { caseId: string }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ accepted: false, notes: '' });
    const send = (accepted: boolean) => { form.transform((d) => ({ ...d, accepted })); form.post(go('objection', caseId), { preserveScroll: true }); };

    return (
        <div className="mt-3 grid gap-2">
            <Field id="obj-notes" label={t('discipline.response.reason')} error={form.errors.notes}><TextArea id="obj-notes" rows={2} value={form.data.notes} onChange={(v) => form.setData('notes', v)} /></Field>
            <div className="flex gap-2">
                <button type="button" className={primaryBtn} onClick={() => send(true)}>{t('discipline.actions.accept')}</button>
                <button type="button" className={secondaryBtn} onClick={() => send(false)}>{t('discipline.actions.reject')}</button>
            </div>
        </div>
    );
}

function ReasonAction({ caseId, name, label, danger = false }: { caseId: string; name: string; label: string; danger?: boolean }): JSX.Element {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    return open ? (
        <form onSubmit={(e) => { e.preventDefault(); form.post(go(name, caseId), { preserveScroll: true }); }} className="mt-3 grid gap-2">
            <Field id={`${name}-reason`} label={t('discipline.end.reason')} error={form.errors.reason}><TextArea id={`${name}-reason`} rows={2} value={form.data.reason} onChange={(v) => form.setData('reason', v)} /></Field>
            <div><button type="submit" className={danger ? dangerBtn : primaryBtn} disabled={form.processing}>{label}</button></div>
        </form>
    ) : (
        <button type="button" className={`${danger ? dangerBtn : secondaryBtn} mt-3`} onClick={() => setOpen(true)}>{label}</button>
    );
}

function WitnessForm({ caseId, sides }: { caseId: string; sides: string[] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({
        called_by: 'institution', name: '', age: '', address: '', occupation: '', relationship_disclosure: '', statement: '',
        cross_examination: '', committee_questions: '', written_testimony: false, heard_in_absence_of_other_party: false, heard_at: '',
    });
    const text = (key: 'name' | 'address' | 'occupation' | 'relationship_disclosure', label: string) => (
        <Field id={`w-${key}`} label={label} error={form.errors[key]}><input id={`w-${key}`} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} required /></Field>
    );
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, age: d.age === '' ? null : Number(d.age) }));
        form.post(go('witnesses.store', caseId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            <Field id="w-side" label={t('discipline.witness.calledBy')} error={form.errors.called_by}>
                <select id="w-side" className={inputCls} value={form.data.called_by} onChange={(e) => form.setData('called_by', e.target.value)}>
                    {sides.map((s) => <option key={s} value={s}>{t(`discipline.sides.${s}`)}</option>)}
                </select>
            </Field>
            {text('name', t('discipline.witness.name'))}
            <Field id="w-age" label={t('discipline.witness.age')} error={form.errors.age}><input id="w-age" type="number" min={14} max={120} className={inputCls} value={form.data.age} onChange={(e) => form.setData('age', e.target.value)} /></Field>
            {text('address', t('discipline.witness.address'))}
            {text('occupation', t('discipline.witness.occupation'))}
            {text('relationship_disclosure', t('discipline.witness.relationship'))}
            <Field id="w-heard" label={t('discipline.witness.heardAt')} error={form.errors.heard_at}><LocalizedDateTime id="w-heard" value={form.data.heard_at} onChange={(v) => form.setData('heard_at', v)} required /></Field>
            <div className="sm:col-span-2"><Field id="w-stmt" label={t('discipline.witness.statement')} error={form.errors.statement}><TextArea id="w-stmt" rows={4} value={form.data.statement} onChange={(v) => form.setData('statement', v)} /></Field></div>
            <div className="sm:col-span-2"><Field id="w-cross" label={t('discipline.witness.crossExamination')}><TextArea id="w-cross" rows={2} value={form.data.cross_examination} onChange={(v) => form.setData('cross_examination', v)} /></Field></div>
            <div className="sm:col-span-2"><Field id="w-q" label={t('discipline.witness.committeeQuestions')}><TextArea id="w-q" rows={2} value={form.data.committee_questions} onChange={(v) => form.setData('committee_questions', v)} /></Field></div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.written_testimony} onChange={(e) => form.setData('written_testimony', e.target.checked)} />{t('discipline.witness.writtenTestimony')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.heard_in_absence_of_other_party} onChange={(e) => form.setData('heard_in_absence_of_other_party', e.target.checked)} />{t('discipline.witness.heardInAbsence')}</label>
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.recordWitness')}</button></div>
        </form>
    );
}

function PenaltyFields({ penalties, track, data, setData, errors }: {
    penalties: Penalty[]; track: string; data: { penalty: string; penalty_amount: string };
    setData: (key: 'penalty' | 'penalty_amount', value: string) => void; errors: Record<string, string | undefined>;
}): JSX.Element {
    const { t } = useLocale();
    const chosen = penalties.find((p) => p.value === data.penalty);

    return (
        <>
            <Field id="penalty" label={t('discipline.recommendation.penalty')} error={errors.penalty}>
                <select id="penalty" className={inputCls} value={data.penalty} onChange={(e) => setData('penalty', e.target.value)} required>
                    <option value="" disabled>—</option>
                    {penalties.filter((p) => track === 'formal' || p.severity === 'minor').map((p) => <option key={p.value} value={p.value}>{t(`discipline.penalties.${p.value}`)}</option>)}
                </select>
            </Field>
            {chosen?.max && (
                <Field id="penalty_amount" label={`${t('discipline.recommendation.amount')} (1–${chosen.max} ${t(`discipline.units.${chosen.unit}`)})`} error={errors.penalty_amount}>
                    <input id="penalty_amount" type="number" min={1} max={chosen.max} className={inputCls} value={data.penalty_amount} onChange={(e) => setData('penalty_amount', e.target.value)} required />
                </Field>
            )}
        </>
    );
}

function RecommendationForm({ caseId, track, penalties, votingMembers }: { caseId: string; track: string; penalties: Penalty[]; votingMembers: Props['votingMembers'] }): JSX.Element {
    const { t, locale } = useLocale();
    const form = useForm({
        finding: '', penalty: '', penalty_amount: '', gravity_assessment: '', conduct_assessment: '', prior_record_assessment: '', reasoning: '',
        votes: Object.fromEntries(votingMembers.map((m) => [m.id, { vote: '', dissent_reason: '' }])) as Record<string, { vote: string; dissent_reason: string }>,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const responsible = form.data.finding === 'responsible';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            penalty: responsible ? d.penalty || null : null,
            penalty_amount: responsible && d.penalty_amount ? Number(d.penalty_amount) : null,
            votes: track === 'formal' ? Object.fromEntries(Object.entries(d.votes).filter(([, v]) => v.vote !== '')) : undefined,
        }));
        form.post(go('recommendation', caseId), { preserveScroll: true });
    };
    const setVote = (id: string, key: 'vote' | 'dissent_reason', value: string) => form.setData('votes', { ...form.data.votes, [id]: { ...form.data.votes[id], [key]: value } });

    return (
        <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-2">
            <Field id="finding" label={t('discipline.recommendation.finding')} error={errors.finding}>
                <select id="finding" className={inputCls} value={form.data.finding} onChange={(e) => form.setData('finding', e.target.value)} required>
                    <option value="" disabled>—</option>
                    {['responsible', 'not_established'].map((f) => <option key={f} value={f}>{t(`discipline.findings.${f}`)}</option>)}
                </select>
            </Field>
            {responsible && <PenaltyFields penalties={penalties} track={track} data={form.data} setData={(k, v) => form.setData(k, v)} errors={errors} />}
            {responsible && (['gravity_assessment', 'conduct_assessment', 'prior_record_assessment'] as const).map((k) => (
                <div key={k} className="sm:col-span-2">
                    <Field id={k} label={t(`discipline.recommendation.${k === 'gravity_assessment' ? 'gravity' : k === 'conduct_assessment' ? 'conduct' : 'priorRecord'}`)} error={errors[k]}>
                        <TextArea id={k} rows={2} value={form.data[k]} onChange={(v) => form.setData(k, v)} />
                    </Field>
                </div>
            ))}
            <div className="sm:col-span-2"><Field id="reasoning" label={t('discipline.recommendation.reasoning')} error={errors.reasoning}><TextArea id="reasoning" rows={4} value={form.data.reasoning} onChange={(v) => form.setData('reasoning', v)} /></Field></div>
            {track === 'formal' && (
                <fieldset className="grid gap-2 sm:col-span-2">
                    <legend className="mb-1 text-xs font-medium text-gray-600 dark:text-slate-400">{t('discipline.recommendation.votes')}</legend>
                    {votingMembers.map((m) => (
                        <div key={m.id} className="grid gap-2 sm:grid-cols-3">
                            <span className="text-sm">{employeeLabel(m.employee, locale)} <span className="text-xs text-gray-500">· {t(`discipline.roles.${m.role}`)}</span></span>
                            <select aria-label={employeeLabel(m.employee, locale)} className={inputCls} value={form.data.votes[m.id]?.vote ?? ''} onChange={(e) => setVote(m.id, 'vote', e.target.value)}>
                                <option value="">{t('discipline.recommendation.absent')}</option>
                                <option value="for">{t('discipline.recommendation.voteFor')}</option>
                                <option value="against">{t('discipline.recommendation.voteAgainst')}</option>
                            </select>
                            {form.data.votes[m.id]?.vote === 'against' && (
                                <input aria-label={t('discipline.recommendation.dissentReason')} placeholder={t('discipline.recommendation.dissentReason')} className={inputCls} value={form.data.votes[m.id].dissent_reason} onChange={(e) => setVote(m.id, 'dissent_reason', e.target.value)} required />
                            )}
                        </div>
                    ))}
                    {errors.votes && <p className="text-xs text-red-700 dark:text-red-400">{errors.votes}</p>}
                </fieldset>
            )}
            {(errors.response || errors.final_statement) && <p className="text-xs text-red-700 sm:col-span-2 dark:text-red-400">{errors.response ?? errors.final_statement}</p>}
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.adopt')}</button></div>
        </form>
    );
}

function RecommendationCard({ r, members }: { r: Recommendation; members: Member[] }): JSX.Element {
    const { t, locale } = useLocale();
    const { dateTime } = useDates();

    return (
        <div className={`mb-2 rounded-control border p-3 text-sm ${r.status === 'superseded' ? 'border-gray-100 opacity-60' : 'border-gray-200'} dark:border-slate-800`}>
            <p className="font-medium">{t('discipline.recommendation.version')} {r.version} · {t(`discipline.findings.${r.finding}`)} — {penaltyLabel(t, r.penalty, r.penalty_amount, r.penalty_unit)}</p>
            <p className="text-xs text-gray-500">{dateTime(r.adopted_at)}{r.votes_for !== null ? ` · ${t('discipline.recommendation.result')}: ${r.votes_for}–${r.votes_against}` : ''}{r.tie_broken_by_chair ? ` · ${t('discipline.recommendation.tieBroken')}` : ''}</p>
            <p className="mt-1 whitespace-pre-line">{r.reasoning}</p>
            {r.dissents.length > 0 && (
                <div className="mt-2">
                    <p className="text-xs font-medium">{t('discipline.recommendation.dissents')}</p>
                    <ul className="list-disc ps-5 text-xs text-gray-700 dark:text-slate-300">
                        {r.dissents.map((d) => <li key={d.member_id}>{employeeLabel(members.find((m) => m.id === d.member_id)?.employee ?? null, locale)}: {d.reason}</li>)}
                    </ul>
                </div>
            )}
        </div>
    );
}

function DecisionForm({ caseId, track, penalties, actions }: { caseId: string; track: string; penalties: Penalty[]; actions: string[] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ action: 'approve', finding: '', penalty: '', penalty_amount: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const ownFinding = form.data.action === 'modify' || form.data.action === 'overturn';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            action: d.action, reason: d.reason,
            finding: ownFinding ? d.finding : null,
            penalty: ownFinding && d.finding === 'responsible' ? d.penalty || null : null,
            penalty_amount: ownFinding && d.finding === 'responsible' && d.penalty_amount ? Number(d.penalty_amount) : null,
        }));
        form.post(go('decision', caseId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-2">
            <Field id="dec-action" label={t('discipline.decision.action')} error={errors.action}>
                <select id="dec-action" className={inputCls} value={form.data.action} onChange={(e) => form.setData('action', e.target.value)}>
                    {actions.filter((a) => a !== 'refer_to_committee' || track === 'expedited').map((a) => <option key={a} value={a}>{t(`discipline.headActions.${a}`)}</option>)}
                </select>
            </Field>
            {ownFinding && (
                <Field id="dec-finding" label={t('discipline.recommendation.finding')} error={errors.finding}>
                    <select id="dec-finding" className={inputCls} value={form.data.finding} onChange={(e) => form.setData('finding', e.target.value)} required>
                        <option value="" disabled>—</option>
                        {['responsible', 'not_established'].map((f) => <option key={f} value={f}>{t(`discipline.findings.${f}`)}</option>)}
                    </select>
                </Field>
            )}
            {ownFinding && form.data.finding === 'responsible' && <PenaltyFields penalties={penalties} track={track} data={form.data} setData={(k, v) => form.setData(k, v)} errors={errors} />}
            <div className="sm:col-span-2"><Field id="dec-reason" label={t('discipline.decision.reason')} error={errors.reason}><TextArea id="dec-reason" rows={3} value={form.data.reason} onChange={(v) => form.setData('reason', v)} /></Field></div>
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.decide')}</button></div>
        </form>
    );
}

function DeliveryForm({ caseId }: { caseId: string }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ delivered_at: '', method: 'personal', acknowledged_at: '' });

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.transform((d) => ({ ...d, acknowledged_at: d.acknowledged_at || null })); form.post(go('delivery', caseId), { preserveScroll: true }); }} className="mt-3 grid gap-3 sm:grid-cols-3">
            <Field id="dl-at" label={t('discipline.decision.deliveredAt')} error={form.errors.delivered_at}><LocalizedDateTime id="dl-at" value={form.data.delivered_at} onChange={(v) => form.setData('delivered_at', v)} required /></Field>
            <Field id="dl-method" label={t('discipline.summons.method')} error={form.errors.method}>
                <select id="dl-method" className={inputCls} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                    {['personal', 'family_member', 'posted'].map((m) => <option key={m} value={m}>{t(`discipline.deliveryMethods.${m}`)}</option>)}
                </select>
            </Field>
            <Field id="dl-ack" label={t('discipline.decision.acknowledgedAt')} error={form.errors.acknowledged_at}><LocalizedDateTime id="dl-ack" value={form.data.acknowledged_at} onChange={(v) => form.setData('acknowledged_at', v)} /></Field>
            <p className="text-xs text-gray-500 sm:col-span-3">{t('discipline.decision.appealNote')}</p>
            <div className="sm:col-span-3"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.deliver')}</button></div>
        </form>
    );
}

/**
 * Regulation 201/2018 Art. 12: the head's suspension order with its written
 * reason, lifting it, and paying back withheld salary once the case ends.
 */
function SuspensionActions({ caseId, can, dueBackPay }: { caseId: string; can: Abilities; dueBackPay: string[] }): JSX.Element | null {
    const { t } = useLocale();
    const order = useForm({ starts_on: '', without_pay: false, ends_on: '', reason: '', served_at: '', service_method: 'personal' });
    const lift = useForm({ lifted_on: '', reason: '' });
    const backPay = useForm({ suspension_id: dueBackPay[0] ?? '', paid_on: '', note: '' });

    if (!can.suspend && !can.liftSuspension && !can.suspensionBackPay) return null;

    return (
        <div className="mt-3 space-y-4 text-sm">
            {can.suspend && (
                <form onSubmit={(e) => { e.preventDefault(); order.transform((d) => ({ ...d, ends_on: d.ends_on || null })); order.post(go('suspension.store', caseId), { preserveScroll: true }); }} className="grid gap-3">
                    <Field id="su-start" label={t('discipline.suspension.startsOn')} error={order.errors.starts_on}>
                        <LocalizedDatePicker id="su-start" value={order.data.starts_on} onChange={(v) => order.setData('starts_on', v)} required />
                    </Field>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={order.data.without_pay} onChange={(e) => order.setData('without_pay', e.target.checked)} />{t('discipline.suspension.withoutPay')}</label>
                    <Field id="su-end" label={t('discipline.suspension.endsOn')} error={order.errors.ends_on}>
                        <LocalizedDatePicker id="su-end" value={order.data.ends_on} onChange={(v) => order.setData('ends_on', v)} required={order.data.without_pay} />
                    </Field>
                    <p className="text-xs text-gray-500">{t('discipline.suspension.limitHelp')}</p>
                    <Field id="su-reason" label={t('discipline.suspension.reason')} error={order.errors.reason}><TextArea id="su-reason" rows={3} value={order.data.reason} onChange={(v) => order.setData('reason', v)} /></Field>
                    <Field id="su-served" label={t('discipline.suspension.servedAt')} error={order.errors.served_at}>
                        <LocalizedDatePicker id="su-served" value={order.data.served_at} onChange={(v) => order.setData('served_at', v)} required />
                    </Field>
                    <Field id="su-method" label={t('discipline.summons.method')} error={order.errors.service_method}>
                        <select id="su-method" className={inputCls} value={order.data.service_method} onChange={(e) => order.setData('service_method', e.target.value)}>
                            {['personal', 'family_member', 'posted'].map((m) => <option key={m} value={m}>{t(`discipline.deliveryMethods.${m}`)}</option>)}
                        </select>
                    </Field>
                    <div><button type="submit" className={dangerBtn} disabled={order.processing}>{t('discipline.suspension.order')}</button></div>
                </form>
            )}
            {can.liftSuspension && (
                <form onSubmit={(e) => { e.preventDefault(); lift.post(go('suspension.lift', caseId), { preserveScroll: true }); }} className="grid gap-3">
                    <Field id="su-lift" label={t('discipline.suspension.liftedOn')} error={lift.errors.lifted_on}>
                        <LocalizedDatePicker id="su-lift" value={lift.data.lifted_on} onChange={(v) => lift.setData('lifted_on', v)} required />
                    </Field>
                    <Field id="su-lift-reason" label={t('discipline.suspension.liftReason')} error={lift.errors.reason}><TextArea id="su-lift-reason" rows={2} value={lift.data.reason} onChange={(v) => lift.setData('reason', v)} /></Field>
                    <div><button type="submit" className={secondaryBtn} disabled={lift.processing}>{t('discipline.suspension.lift')}</button></div>
                </form>
            )}
            {can.suspensionBackPay && (
                <form onSubmit={(e) => { e.preventDefault(); backPay.post(go('suspension.back-pay', caseId), { preserveScroll: true }); }} className="grid gap-3">
                    <p className="text-xs text-gray-500">{t('discipline.suspension.backPayHelp')}</p>
                    <Field id="su-paid" label={t('discipline.suspension.paidOn')} error={backPay.errors.paid_on ?? backPay.errors.suspension_id}>
                        <LocalizedDatePicker id="su-paid" value={backPay.data.paid_on} onChange={(v) => backPay.setData('paid_on', v)} required />
                    </Field>
                    <Field id="su-paid-note" label={t('discipline.suspension.paidNote')} error={backPay.errors.note}><TextArea id="su-paid-note" rows={2} value={backPay.data.note} onChange={(v) => backPay.setData('note', v)} /></Field>
                    <div><button type="submit" className={primaryBtn} disabled={backPay.processing}>{t('discipline.suspension.recordBackPay')}</button></div>
                </form>
            )}
        </div>
    );
}

/**
 * The institution's side of an appeal to the Administrative Court (Regulation
 * 201/2018 Part Five): the employee's notice (Art. 37(1)), the certified record
 * copy (Art. 37(2)), the filing, the court's outcome and its implementation
 * (Arts. 53, 54). Dates use the calendar of the interface language.
 */
function AppealActions({ caseId, can }: { caseId: string; can: Abilities }): JSX.Element | null {
    const { t } = useLocale();
    const notice = useForm({ notice_received_at: '', record_copy_requested: false });
    const copy = useForm({ delivered_at: '' });
    const filing = useForm({ filed_on: '', court_case_number: '' });
    const outcome = useForm({ outcome: 'upheld', decided_on: '', received_at: '', summary: '' });
    const implemented = useForm({ implemented_at: '', note: '' });

    if (!can.appealNotice && !can.appealRecordCopy && !can.appealFiling && !can.appealOutcome && !can.appealImplemented) return null;

    return (
        <div className="mt-4 space-y-4 rounded-control border border-gray-200 p-3 text-sm dark:border-slate-700">
            <p className="font-semibold">{t('discipline.appeal.title')}</p>
            {can.appealNotice && (
                <form onSubmit={(e) => { e.preventDefault(); notice.post(go('appeal.notice', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-2">
                    <Field id="ap-notice" label={t('discipline.appeal.noticeReceivedAt')} error={notice.errors.notice_received_at}>
                        <LocalizedDatePicker id="ap-notice" value={notice.data.notice_received_at} onChange={(v) => notice.setData('notice_received_at', v)} required />
                    </Field>
                    <label className="flex items-center gap-2 self-end text-sm">
                        <input type="checkbox" checked={notice.data.record_copy_requested} onChange={(e) => notice.setData('record_copy_requested', e.target.checked)} />
                        {t('discipline.appeal.recordCopyRequested')}
                    </label>
                    <p className="text-xs text-gray-500 sm:col-span-2">{t('discipline.appeal.noticeHelp')}</p>
                    <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={notice.processing}>{t('discipline.appeal.recordNotice')}</button></div>
                </form>
            )}
            {can.appealRecordCopy && (
                <form onSubmit={(e) => { e.preventDefault(); copy.post(go('appeal.record-copy', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-2">
                    <Field id="ap-copy" label={t('discipline.appeal.recordCopyDeliveredAt')} error={copy.errors.delivered_at}>
                        <LocalizedDatePicker id="ap-copy" value={copy.data.delivered_at} onChange={(v) => copy.setData('delivered_at', v)} required />
                    </Field>
                    <div className="self-end"><button type="submit" className={secondaryBtn} disabled={copy.processing}>{t('discipline.appeal.recordCopyGiven')}</button></div>
                </form>
            )}
            {can.appealFiling && (
                <form onSubmit={(e) => { e.preventDefault(); filing.post(go('appeal.filing', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-3">
                    <Field id="ap-filed" label={t('discipline.appeal.filedOn')} error={filing.errors.filed_on}>
                        <LocalizedDatePicker id="ap-filed" value={filing.data.filed_on} onChange={(v) => filing.setData('filed_on', v)} required />
                    </Field>
                    <Field id="ap-number" label={t('discipline.appeal.courtCaseNumber')} error={filing.errors.court_case_number}>
                        <input id="ap-number" className={inputCls} maxLength={100} value={filing.data.court_case_number} onChange={(e) => filing.setData('court_case_number', e.target.value)} />
                    </Field>
                    <div className="self-end"><button type="submit" className={secondaryBtn} disabled={filing.processing}>{t('discipline.appeal.recordFiling')}</button></div>
                </form>
            )}
            {can.appealOutcome && (
                <form onSubmit={(e) => { e.preventDefault(); outcome.post(go('appeal.outcome', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-3">
                    <Field id="ap-outcome" label={t('discipline.appeal.outcome')} error={outcome.errors.outcome}>
                        <select id="ap-outcome" className={inputCls} value={outcome.data.outcome} onChange={(e) => outcome.setData('outcome', e.target.value)}>
                            {['upheld', 'modified', 'reversed', 'remanded', 'withdrawn'].map((o) => <option key={o} value={o}>{t(`discipline.appeal.outcomes.${o}`)}</option>)}
                        </select>
                    </Field>
                    <Field id="ap-decided" label={t('discipline.appeal.decidedOn')} error={outcome.errors.decided_on}>
                        <LocalizedDatePicker id="ap-decided" value={outcome.data.decided_on} onChange={(v) => outcome.setData('decided_on', v)} required />
                    </Field>
                    <Field id="ap-received" label={t('discipline.appeal.receivedAt')} error={outcome.errors.received_at}>
                        <LocalizedDatePicker id="ap-received" value={outcome.data.received_at} onChange={(v) => outcome.setData('received_at', v)} required />
                    </Field>
                    <div className="sm:col-span-3"><Field id="ap-summary" label={t('discipline.appeal.summary')} error={outcome.errors.summary}><TextArea id="ap-summary" rows={3} value={outcome.data.summary} onChange={(v) => outcome.setData('summary', v)} /></Field></div>
                    <p className="text-xs text-gray-500 sm:col-span-3">{t('discipline.appeal.outcomeHelp')}</p>
                    <div className="sm:col-span-3"><button type="submit" className={primaryBtn} disabled={outcome.processing}>{t('discipline.appeal.recordOutcome')}</button></div>
                </form>
            )}
            {can.appealImplemented && (
                <form onSubmit={(e) => { e.preventDefault(); implemented.post(go('appeal.implemented', caseId), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-2">
                    <Field id="ap-impl" label={t('discipline.appeal.implementedAt')} error={implemented.errors.implemented_at}>
                        <LocalizedDatePicker id="ap-impl" value={implemented.data.implemented_at} onChange={(v) => implemented.setData('implemented_at', v)} required />
                    </Field>
                    <div className="sm:col-span-2"><Field id="ap-impl-note" label={t('discipline.appeal.implementationNote')} error={implemented.errors.note}><TextArea id="ap-impl-note" rows={2} value={implemented.data.note} onChange={(v) => implemented.setData('note', v)} /></Field></div>
                    <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={implemented.processing}>{t('discipline.appeal.recordImplemented')}</button></div>
                </form>
            )}
        </div>
    );
}
