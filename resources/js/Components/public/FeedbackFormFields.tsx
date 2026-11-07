import { RequirementMark, cardClass, errorClass, fieldClass, labelClass, mutedClass } from '@/Components/public/feedbackFormParts';
import { useLocale } from '@/hooks/useLocale';

/**
 * Service-feedback fields from the owner's sample form (2026-11-08), shared
 * by the employee-QR and institution-QR feedback forms: the work unit or
 * window where the service was received, and the kind of feedback. Both
 * optional; the server keeps them on the row (`service_point`, `feedback_kind`).
 */

export const FEEDBACK_KINDS = ['constructive', 'improvement', 'appreciation'] as const;
export type FeedbackKind = '' | (typeof FEEDBACK_KINDS)[number];

const f = (key: string) => `serviceFeedback.feedbackForm.${key}`;

export function ServicePointField({ value, onChange, error }: { value: string; onChange: (value: string) => void; error?: string }) {
    const { t } = useLocale();
    return (
        <div className={cardClass} data-field="service_point">
            <label htmlFor="service-point" className={labelClass}>{t(f('servicePoint'))}<RequirementMark required={false} /></label>
            <p id="service-point-hint" className={`mt-1 text-xs ${mutedClass}`}>{t(f('servicePointHint'))}</p>
            <input id="service-point" maxLength={120} aria-describedby="service-point-hint" aria-invalid={error ? true : undefined} value={value} onChange={(event) => onChange(event.target.value)} className={fieldClass} />
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

export function FeedbackKindChooser({ value, onChange, error }: { value: FeedbackKind; onChange: (value: FeedbackKind) => void; error?: string }) {
    const { t } = useLocale();
    return (
        <fieldset className={cardClass} data-field="feedback_kind">
            <legend className="sr-only">{t(f('kind'))}</legend>
            <p aria-hidden="true" className={labelClass}>{t(f('kind'))}<RequirementMark required={false} /></p>
            <div className="mt-2 grid gap-2 sm:grid-cols-3">
                {FEEDBACK_KINDS.map((kind) => (
                    <label key={kind} className="flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border border-(--app-border) px-3 py-2 has-checked:border-primary has-checked:bg-primary/5">
                        <input type="radio" name="feedback-kind" value={kind} checked={value === kind} onChange={() => onChange(kind)} className="h-4 w-4 shrink-0" />
                        <span className="text-sm">{t(f(`kinds.${kind}`))}</span>
                    </label>
                ))}
            </div>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </fieldset>
    );
}
