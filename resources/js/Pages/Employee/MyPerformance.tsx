import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { CheckinList, DevelopmentPlans, EvidenceList, ItemsTable, ResultPanel, ReviewSummary, agreementHeading, type AgreementView } from '@/Components/performance/agreement';
import { DevelopmentPlanForm, EvidenceForm } from '@/Components/performance/forms';
import { Field, Pill, Section, fill, formatScore, inputCls, nameOf, primaryBtn, secondaryBtn, smallBtn, useEnumLabel } from '@/Components/performance/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Props = {
    agreements: { id: string; status: string; cycle: { name_en: string; name_am: string | null } | null; effective_from: string; effective_to: string; is_temporary: boolean }[];
    agreement: AgreementView | null;
    appeals: { id: string; appeal_no: string; status: string; decision: string | null; decision_reason: string | null; decided_score: string | null; submitted_at: string }[];
    appealWindowDays: number;
    selfAssessmentRequired: boolean;
    can: { selfAssess: Record<'MID_YEAR' | 'YEAR_END', boolean>; appeal: boolean };
};

/**
 * My Portal → My Performance. The employee's own agreement only; private
 * manager notes, PIPs and unreleased scores are removed on the server.
 */
export default function MyPerformance(props: Props) {
    return <PerformanceBody key={props.agreement?.id ?? 'empty'} {...props} />;
}

function PerformanceBody({ agreements, agreement, appeals, appealWindowDays, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [open, setOpen] = useState<'evidence' | 'idp' | 'appeal' | null>(null);
    const [processing, setProcessing] = useState(false);
    const toggle = (key: typeof open) => setOpen(open === key ? null : key);

    async function acknowledge() {
        if (!agreement) return;
        const { confirmed } = await confirm({ title: t('performance.actions.acknowledge'), description: t('performance.my.acknowledgeHelp'), confirmLabel: t('performance.actions.acknowledge'), cancelLabel: t('performance.actions.cancel') });
        if (confirmed) {
            setProcessing(true);
            router.post(route('employee.performance.acknowledge', agreement.id), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
        }
    }

    async function returnAgreement() {
        if (!agreement) return;
        const { confirmed, reason } = await confirm({ title: t('performance.actions.return'), confirmLabel: t('performance.actions.return'), cancelLabel: t('performance.actions.cancel'), requireReason: true, reasonLabel: t('performance.fields.reason') });
        if (confirmed) {
            setProcessing(true);
            router.post(route('employee.performance.return', agreement.id), { reason }, { preserveScroll: true, onFinish: () => setProcessing(false) });
        }
    }

    const cycleOpen = agreement && ['ACTIVE', 'UNDER_REVIEW'].includes(agreement.status);

    return (
        <PortalPage title={t('performance.my.title')} description={t('performance.my.description')}>
            {agreements.length > 1 && (
                <div className="flex flex-wrap gap-2">
                    {agreements.map((a) => (
                        <button key={a.id} type="button" onClick={() => router.get(route('employee.performance.index'), { agreement: a.id }, { preserveScroll: true })}
                            className={`${smallBtn} ${agreement?.id === a.id ? 'ring-2 ring-[color:var(--color-primary)]' : ''}`}>
                            {nameOf(a.cycle, locale)} · <LocalizedDateDisplay value={a.effective_from} />{a.is_temporary ? ` · ${t('performance.fields.temporary')}` : ''}
                        </button>
                    ))}
                </div>
            )}

            {!agreement ? (
                <Section title={t('performance.my.noAgreement')} description={t('performance.my.noAgreementHelp')}>
                    <ol className="grid gap-4 py-3 md:grid-cols-3">
                        {['agreeStep', 'recordStep', 'reviewStep'].map((step, index) => <li key={step} className="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-slate-700 dark:bg-slate-800/40">
                            <span className="mb-3 flex h-8 w-8 items-center justify-center rounded-full bg-white text-sm font-semibold text-[color:var(--color-primary)] shadow-sm dark:bg-slate-900">{index + 1}</span>
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t(`performance.my.${step}`)}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-gray-500 dark:text-slate-400">{t(`performance.my.${step}Help`)}</p>
                        </li>)}
                    </ol>
                </Section>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-slate-400">
                        <Pill group="agreement" value={agreement.status} />
                        <span>{agreementHeading(agreement, locale)}</span>
                        <span><LocalizedDateDisplay value={agreement.effective_from} /> – <LocalizedDateDisplay value={agreement.effective_to} /></span>
                        {agreement.manager && <span>{t('performance.fields.manager')}: {agreement.manager}</span>}
                    </div>

                    {agreement.status === 'PENDING_EMPLOYEE_REVIEW' && (
                        <div className="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <p>{t('performance.my.acknowledgeHelp')}</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button type="button" disabled={processing} className={primaryBtn} onClick={acknowledge}>{t('performance.actions.acknowledge')}</button>
                                <button type="button" disabled={processing} className={secondaryBtn} onClick={returnAgreement}>{t('performance.actions.return')}</button>
                            </div>
                        </div>
                    )}

                    <Section title={t('performance.agreements.items')} description={t('performance.my.progressNote')}>
                        <ItemsTable items={agreement.items} />
                    </Section>

                    <div className="grid gap-6 lg:grid-cols-2">
                        {(['MID_YEAR', 'YEAR_END'] as const).map((type) => {
                            const review = agreement.reviews[type];
                            const canSubmit = can.selfAssess[type];
                            return (
                                <Section key={type} title={type === 'MID_YEAR' ? t('performance.agreements.midYear') : t('performance.agreements.yearEnd')}>
                                    <ReviewSummary review={review} />
                                    {canSubmit && <SelfAssessmentForm agreement={agreement} type={type} />}
                                    {!canSubmit && (!review || ['DRAFT', 'RETURNED'].includes(review.status)) && <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">{t('performance.my.reviewUnavailable')}</p>}
                                </Section>
                            );
                        })}
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Section title={t('performance.agreements.checkins')}>
                            <CheckinList checkins={agreement.checkins}>{(c) => cycleOpen ? <CheckinNote key={c.id} checkin={c} /> : null}</CheckinList>
                        </Section>
                        <Section title={t('performance.fields.evidence')} actions={['AGREED', 'ACTIVE', 'UNDER_REVIEW'].includes(agreement.status) && <button type="button" className={smallBtn} onClick={() => toggle('evidence')}>{t('performance.actions.upload')}</button>}>
                            {open === 'evidence' && <div className="mb-3"><EvidenceForm url={route('employee.performance.evidence.store', agreement.id)} items={agreement.items.map((i) => ({ id: i.id, label: `${i.kpi.code} — ${nameOf(i.kpi, locale)}` }))} onDone={() => setOpen(null)} /></div>}
                            <EvidenceList evidence={agreement.evidence} />
                        </Section>
                    </div>

                    <Section title={t('performance.agreements.result')} actions={can.appeal && <button type="button" className={smallBtn} onClick={() => toggle('appeal')}>{t('performance.actions.appeal')}</button>}>
                        {open === 'appeal' && agreement.result && <AppealForm resultId={agreement.result.id} windowDays={appealWindowDays} onDone={() => setOpen(null)} />}
                        <ResultPanel result={agreement.result} hidden={agreement.result_hidden} />
                    </Section>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Section title={t('performance.agreements.idps')} actions={<button type="button" className={smallBtn} onClick={() => toggle('idp')}>{t('performance.actions.add')}</button>}>
                            {open === 'idp' && <div className="mb-3"><DevelopmentPlanForm url={route('employee.performance.idps.store', agreement.id)} competencies={agreement.competencies} onDone={() => setOpen(null)} /></div>}
                            <DevelopmentPlans plans={agreement.development_plans} />
                        </Section>
                        <Section title={t('performance.appeals.mine')} description={fill(t('performance.appeals.windowNote'), { days: appealWindowDays })}>
                            {appeals.length === 0 ? <p className="text-sm text-gray-500">—</p> : (
                                <ul className="space-y-2 text-sm">
                                    {appeals.map((a) => (
                                        <li key={a.id} className="rounded-lg bg-gray-50 p-2 dark:bg-slate-800/60">
                                            <p className="flex flex-wrap items-center gap-2"><span className="font-medium">{a.appeal_no}</span><Pill group="appeal" value={a.status} />{a.decision && <Pill group="decision" value={a.decision} />}</p>
                                            <p className="text-xs text-gray-500"><LocalizedDateDisplay value={a.submitted_at} withTime />{a.decided_score !== null && ` · ${t('performance.fields.score')}: ${formatScore(a.decided_score)}`}</p>
                                            {a.decision_reason && <p className="mt-1 text-xs">{a.decision_reason}</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-slate-400">{label('agreement', agreement.status)} · v{agreement.version}</p>
                </>
            )}
        </PortalPage>
    );
}

function SelfAssessmentForm({ agreement, type }: { agreement: AgreementView; type: 'MID_YEAR' | 'YEAR_END' }) {
    const { t, locale } = useLocale();
    const review = agreement.reviews[type];
    const form = useForm<{ employee_self_assessment: string; achievements: string; challenges: string; contributions: string; development_needs: string; self_ratings: Record<string, number> }>({
        employee_self_assessment: review?.employee_self_assessment ?? '', achievements: review?.achievements ?? '', challenges: review?.challenges ?? '',
        contributions: review?.contributions ?? '', development_needs: review?.development_needs ?? '',
        self_ratings: Object.fromEntries(agreement.competencies.filter((c) => c.self_rating !== null).map((c) => [c.competency_id, c.self_rating as number])),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const area = (key: 'employee_self_assessment' | 'achievements' | 'challenges' | 'contributions' | 'development_needs', caption: string, required = false) => (
        <Field label={caption} error={errors[key]}><textarea rows={key === 'employee_self_assessment' ? 4 : 2} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} required={required} /></Field>
    );

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('employee.performance.reviews.submit', [agreement.id, type.toLowerCase()]), { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="mt-4 space-y-3 border-t border-gray-100 pt-3 dark:border-slate-800">
            <p className="text-xs text-gray-500 dark:text-slate-400">{t('performance.my.selfHelp')}</p>
            {area('employee_self_assessment', t('performance.fields.selfAssessment'), true)}
            {area('achievements', t('performance.fields.achievements'))}
            {area('challenges', t('performance.fields.challenges'))}
            {area('contributions', t('performance.fields.contributions'))}
            {area('development_needs', t('performance.fields.developmentNeeds'))}
            {agreement.competencies.length > 0 && (
                <fieldset className="grid gap-2 sm:grid-cols-2">
                    <legend className="mb-1 text-xs font-medium text-gray-600 dark:text-slate-400">{t('performance.fields.selfRating')} (1–5)</legend>
                    {agreement.competencies.map((c) => (
                        <label key={c.competency_id} className="flex items-center justify-between gap-2 text-xs">
                            <span>{c.code} — {(locale === 'am' && c.name_am) || c.name_en}</span>
                            <select className="rounded-lg border border-gray-300 px-2 py-1 dark:border-slate-700 dark:bg-slate-950" value={form.data.self_ratings[c.competency_id] ?? ''} onChange={(e) => {
                                const ratings = { ...form.data.self_ratings };
                                if (e.target.value === '') delete ratings[c.competency_id];
                                else ratings[c.competency_id] = Number(e.target.value);
                                form.setData('self_ratings', ratings);
                            }}>
                                <option value="">—</option>
                                {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </label>
                    ))}
                </fieldset>
            )}
            {(errors.review || errors.window) && <p className="text-xs text-red-700">{errors.review ?? errors.window}</p>}
            <div className="flex justify-end"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.my.submitSelf')}</button></div>
        </form>
    );
}

function CheckinNote({ checkin }: { checkin: AgreementView['checkins'][number] }) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const form = useForm({ employee_summary: checkin.employee_summary ?? '', blockers: checkin.blockers ?? '', support_required: checkin.support_required ?? '', learning_needs: checkin.learning_needs ?? '' });
    if (!open) return <button type="button" className={`${smallBtn} mt-2`} onClick={() => setOpen(true)}>{t('performance.actions.edit')}</button>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('employee.performance.checkins.note', checkin.id), { preserveScroll: true, onSuccess: () => setOpen(false) }); }} className="mt-2 grid gap-2 sm:grid-cols-2">
            <Field label={t('performance.fields.progress')} error={form.errors.employee_summary} className="sm:col-span-2"><textarea rows={2} className={inputCls} value={form.data.employee_summary} onChange={(e) => form.setData('employee_summary', e.target.value)} /></Field>
            <Field label={t('performance.fields.blockers')} error={form.errors.blockers}><input className={inputCls} value={form.data.blockers} onChange={(e) => form.setData('blockers', e.target.value)} /></Field>
            <Field label={t('performance.fields.supportRequired')} error={form.errors.support_required}><input className={inputCls} value={form.data.support_required} onChange={(e) => form.setData('support_required', e.target.value)} /></Field>
            <Field label={t('performance.fields.learningNeeds')} error={form.errors.learning_needs}><input className={inputCls} value={form.data.learning_needs} onChange={(e) => form.setData('learning_needs', e.target.value)} /></Field>
            <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" className={smallBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

function AppealForm({ resultId, windowDays, onDone }: { resultId: string; windowDays: number; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm<{ reason: string; attachment: File | null }>({ reason: '', attachment: null });
    const errors = form.errors as Record<string, string | undefined>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('employee.performance.appeal', resultId), { preserveScroll: true, forceFormData: true, onSuccess: onDone }); }} className="mb-4 space-y-2 rounded-lg bg-gray-50 p-3 dark:bg-slate-800/50">
            <p className="text-xs text-gray-600 dark:text-slate-400">{fill(t('performance.appeals.windowNote'), { days: windowDays })}</p>
            <Field label={t('performance.fields.reason')} error={errors.reason}><textarea rows={4} minLength={20} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></Field>
            <Field label={t('performance.fields.file')} error={errors.attachment}><input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" className="block w-full text-sm" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} /></Field>
            {errors.result && <p className="text-xs text-red-700">{errors.result}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.appeal')}</button>
            </div>
        </form>
    );
}
