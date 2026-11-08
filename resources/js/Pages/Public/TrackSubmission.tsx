import { FormEvent, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { areaClass, cardClass, errorClass, fieldClass, labelClass, mutedClass, sendButtonClass } from '@/Components/public/feedbackFormParts';
import FeedbackAppLayout from '@/Layouts/FeedbackAppLayout';
import { useLocale } from '@/hooks/useLocale';

type Stage = 'received' | 'in_review' | 'decided' | 'closed';
/** ADR 0035: what the complainant was told at each stage, never who decided. */
type HearingStage = { stage: 1 | 2 | 3; opened_at?: string; appeal_reason?: string; decision?: 'answered' | 'upheld' | 'overturned'; response?: string; decided_at?: string; appeal_deadline_at?: string; citizen_outcome?: 'accepted' | 'appealed' | 'lapsed' };
type Hearing = { current: 1 | 2 | 3; next: 2 | 3 | null; stages: HearingStage[]; can_accept: boolean; can_appeal: boolean };
type Result = { reference: string; type: 'suggestion' | 'complaint'; stage: Stage; received_at: string | null; updated_at: string | null; hearing?: Hearing | null };

const STAGES: Stage[] = ['received', 'in_review', 'decided', 'closed'];

/**
 * Track a Tip-off or a complaint by its reference (PublicSubmissionTrackingController).
 * Shows only where it stands and when; a complaint also asks for the phone number
 * it was filed with.
 */
export default function TrackSubmission({ reference = '', result = null }: { reference?: string; result?: Result | null }) {
    const { t } = useLocale();
    const k = (key: string) => t(`serviceFeedback.tracking.${key}`);
    const form = useForm({ reference, phone: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('public-submissions.track.lookup'), { preserveScroll: true });
    };
    const current = result ? STAGES.indexOf(result.stage) : -1;

    return (
        <FeedbackAppLayout title={k('title')} subtitle={k('subtitle')}>
            <form onSubmit={submit} className="space-y-4" noValidate>
                <div className={`${cardClass} space-y-4`}>
                    <div data-field="reference">
                        <label htmlFor="track-reference" className={labelClass}>{k('reference')}</label>
                        <p id="track-reference-hint" className={`mt-1 text-xs ${mutedClass}`}>{k('referenceHint')}</p>
                        <input id="track-reference" required autoComplete="off" autoCapitalize="characters" maxLength={40} aria-describedby="track-reference-hint" aria-invalid={form.errors.reference ? true : undefined}
                            value={form.data.reference} onChange={(event) => { form.setData('reference', event.target.value); form.clearErrors('reference'); }} className={`${fieldClass} font-mono uppercase`} />
                        {form.errors.reference && <p role="alert" className={errorClass}>{form.errors.reference}</p>}
                    </div>
                    <div data-field="phone">
                        <label htmlFor="track-phone" className={labelClass}>{k('phone')}</label>
                        <p id="track-phone-hint" className={`mt-1 text-xs ${mutedClass}`}>{k('phoneHint')}</p>
                        <input id="track-phone" type="tel" inputMode="tel" autoComplete="tel" maxLength={40} aria-describedby="track-phone-hint"
                            value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} className={fieldClass} />
                        {form.errors.phone && <p role="alert" className={errorClass}>{form.errors.phone}</p>}
                    </div>
                    <button type="submit" disabled={form.processing} className={sendButtonClass}>{form.processing ? k('checking') : k('check')}</button>
                </div>
            </form>

            {result && (
                <section aria-labelledby="track-result-title" aria-live="polite" className={`${cardClass} mt-4 space-y-4`}>
                    <div>
                        <p id="track-result-title" className="text-sm font-semibold text-(--app-foreground)">{k(result.type === 'complaint' ? 'complaintStatus' : 'tipStatus')}</p>
                        <p className="mt-1 font-mono text-base font-bold text-(--app-foreground)">{result.reference}</p>
                    </div>
                    <ol className="space-y-3">
                        {STAGES.map((stage, index) => {
                            const done = index <= current;
                            return (
                                <li key={stage} className="flex items-start gap-3" aria-current={index === current ? 'step' : undefined}>
                                    <span aria-hidden="true" className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${done ? 'bg-primary text-white' : 'bg-(--app-surface-muted) text-(--app-muted-foreground)'}`}>{index + 1}</span>
                                    <span>
                                        <span className={`block text-sm ${index === current ? 'font-semibold text-(--app-foreground)' : done ? 'text-(--app-foreground)' : mutedClass}`}>
                                            {k(`stages.${stage}`)}{index === current ? ` · ${k('current')}` : ''}
                                        </span>
                                        <span className={`block text-xs ${mutedClass}`}>{k(`stageHints.${stage}`)}</span>
                                    </span>
                                </li>
                            );
                        })}
                    </ol>
                    <dl className={`grid grid-cols-2 gap-3 border-t border-(--app-border) pt-3 text-xs ${mutedClass}`}>
                        <div><dt>{k('receivedAt')}</dt><dd className="mt-0.5 text-sm text-(--app-foreground)"><LocalizedDateDisplay value={result.received_at} /></dd></div>
                        <div><dt>{k('updatedAt')}</dt><dd className="mt-0.5 text-sm text-(--app-foreground)"><LocalizedDateDisplay value={result.updated_at} /></dd></div>
                    </dl>
                    <p className={`text-xs leading-relaxed ${mutedClass}`}>{k('privacy')}</p>
                </section>
            )}

            {result?.hearing && <HearingPanel reference={result.reference} hearing={result.hearing} />}
        </FeedbackAppLayout>
    );
}

/** ADR 0035: the responses given so far, and the complainant's answer to the latest one. */
function HearingPanel({ reference, hearing }: { reference: string; hearing: Hearing }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.hearing.${key}`);
    const flash = (usePage().props as { flash?: { success?: string | null } }).flash;
    const [appealing, setAppealing] = useState(false);
    const form = useForm({ reference, action: 'accept' as 'accept' | 'appeal', reason: '' });
    const latest = hearing.stages[hearing.stages.length - 1];
    const errors = form.errors as Record<string, string | undefined>;
    const send = (action: 'accept' | 'appeal') => (event?: FormEvent) => {
        event?.preventDefault();
        form.transform((data) => ({ ...data, action }));
        form.post(route('public-submissions.track.respond'), { preserveScroll: true, onSuccess: () => { setAppealing(false); form.reset('reason'); } });
    };

    return (
        <section aria-labelledby="hearing-title" className={`${cardClass} mt-4 space-y-4`}>
            <div>
                <h2 id="hearing-title" className="text-sm font-semibold text-(--app-foreground)">{h('publicTitle')}</h2>
                <p className={`mt-1 text-xs ${mutedClass}`}>{h('intro')}</p>
            </div>
            {flash?.success && <p role="status" className="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{flash.success}</p>}
            <ol className="space-y-3">
                {hearing.stages.map((row) => (
                    <li key={row.stage} className="rounded-xl border border-(--app-border) p-3">
                        <p className="text-sm font-semibold text-(--app-foreground)">{t(`serviceFeedback.hearing.stageNames.${row.stage}`)}</p>
                        {row.appeal_reason && <p className={`mt-1 text-xs ${mutedClass}`}>{h('appealReason')}: {row.appeal_reason}</p>}
                        {row.response
                            ? <>
                                <p className="mt-2 text-xs font-semibold text-(--app-foreground)">{row.decision && row.decision !== 'answered' ? t(`serviceFeedback.hearing.decisions.${row.decision}`) : h('response')}{row.decided_at && <> · <LocalizedDateDisplay value={row.decided_at} /></>}</p>
                                <p className="mt-1 whitespace-pre-line text-sm text-(--app-foreground)">{row.response}</p>
                            </>
                            : <p className={`mt-2 text-sm ${mutedClass}`}>{row.stage > 1 ? h('appealInReview') : t('serviceFeedback.tracking.stageHints.in_review')}</p>}
                        {row.citizen_outcome && <p className={`mt-2 text-xs ${mutedClass}`}>{t(`serviceFeedback.hearing.outcomes.${row.citizen_outcome}`)}</p>}
                        {row.stage === 3 && row.response && <p className={`mt-2 text-xs ${mutedClass}`}>{h('finalNote')}</p>}
                    </li>
                ))}
            </ol>

            {(hearing.can_accept || hearing.can_appeal) && (
                <div className="space-y-3 border-t border-(--app-border) pt-3">
                    {latest?.appeal_deadline_at && <p className={`text-xs ${mutedClass}`}>{h('answerBy')}: <span className="font-semibold text-(--app-foreground)"><LocalizedDateDisplay value={latest.appeal_deadline_at} /></span> · {h('answerByHint')}</p>}
                    {errors.reference && <p role="alert" className={errorClass}>{errors.reference}</p>}
                    {errors.action && <p role="alert" className={errorClass}>{errors.action}</p>}
                    {!appealing && <div className="grid gap-2">
                        {hearing.can_accept && <button type="button" onClick={() => send('accept')()} disabled={form.processing} className={sendButtonClass}>{h('accept')}</button>}
                        {hearing.can_appeal && hearing.next && <button type="button" onClick={() => setAppealing(true)} disabled={form.processing}
                            className="min-h-[52px] w-full rounded-xl border border-(--app-border) bg-(--app-surface) px-4 text-base font-semibold text-(--app-foreground) hover:bg-(--app-surface-muted)">{t(`serviceFeedback.hearing.appealTo.${hearing.next}`)}</button>}
                    </div>}
                    {appealing && hearing.next && <form onSubmit={send('appeal')} className="space-y-3" noValidate>
                        <div>
                            <label htmlFor="appeal-reason" className={labelClass}>{h('appealReason')}</label>
                            <p id="appeal-reason-hint" className={`mt-1 text-xs ${mutedClass}`}>{h('appealReasonHint')}</p>
                            <textarea id="appeal-reason" rows={4} maxLength={2000} required aria-describedby="appeal-reason-hint" aria-invalid={errors.reason ? true : undefined}
                                value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={areaClass} />
                            {errors.reason && <p role="alert" className={errorClass}>{errors.reason}</p>}
                        </div>
                        <button type="submit" disabled={form.processing} className={sendButtonClass}>{h('sendAppeal')}</button>
                        <button type="button" onClick={() => setAppealing(false)} className={`w-full py-2 text-sm font-semibold ${mutedClass} hover:underline`}>{h('cancel')}</button>
                    </form>}
                </div>
            )}
        </section>
    );
}
