import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { cardClass, errorClass, fieldClass, labelClass, mutedClass, sendButtonClass } from '@/Components/public/feedbackFormParts';
import FeedbackAppLayout from '@/Layouts/FeedbackAppLayout';
import { useLocale } from '@/hooks/useLocale';

type Stage = 'received' | 'in_review' | 'decided' | 'closed';
type Result = { reference: string; type: 'suggestion' | 'complaint'; stage: Stage; received_at: string | null; updated_at: string | null };

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
        </FeedbackAppLayout>
    );
}
