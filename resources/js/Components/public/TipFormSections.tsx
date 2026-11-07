import { RequirementMark, areaClass, cardClass, errorClass, fieldClass, labelClass, mutedClass } from '@/Components/public/feedbackFormParts';
import { ShieldCheck } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';

/**
 * The public Tip-off form's sections (owner's sample, 2026-11-08; server
 * rules in PublicTipForm), shared by the employee-QR and institution-QR
 * Tip-off forms. Every part is optional.
 */

export const VIOLATIONS = ['bribery', 'abuse_of_power', 'document_fraud', 'waste_of_public_property', 'other'] as const;
export type Violation = (typeof VIOLATIONS)[number];

export type TipFormData = {
    confidentiality: '' | 'confidential' | 'not_needed';
    suspect_name: string;
    suspect_unit: string;
    suspect_location: string;
    violation_types: Violation[];
    violation_other: string;
    evidence_description: string;
};

export const emptyTipForm = (): TipFormData => ({
    confidentiality: '', suspect_name: '', suspect_unit: '', suspect_location: '', violation_types: [], violation_other: '', evidence_description: '',
});

type Errors = Record<string, string | undefined>;
type Setter = <K extends keyof TipFormData>(field: K, value: TipFormData[K]) => void;
const k = (key: string) => `serviceFeedback.tipForm.${key}`;

/** The law protects a tipster's identity and keeps it confidential. */
export function TipLegalNotice() {
    const { t } = useLocale();
    return (
        <p className="flex items-start gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm leading-relaxed text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {t(k('legalNotice'))}
        </p>
    );
}

function Text({ id, field, label, value, onChange, error, maxLength = 200 }: { id: string; field: string; label: string; value: string; onChange: (value: string) => void; error?: string; maxLength?: number }) {
    return (
        <div data-field={field}>
            <label htmlFor={id} className={labelClass}>{label}<RequirementMark required={false} /></label>
            <input id={id} maxLength={maxLength} aria-invalid={error ? true : undefined} value={value} onChange={(event) => onChange(event.target.value)} className={fieldClass} />
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/** Section 2: the person suspected, and section 3: the violations alleged and the supporting evidence. */
export function TipSuspectAndViolationFields({ form, set, errors }: { form: TipFormData; set: Setter; errors: Errors }) {
    const { t } = useLocale();
    const e = (key: string) => errors[`tip_form.${key}`];
    return (
        <>
            <div role="group" aria-labelledby="tip-suspect-title" className={`${cardClass} space-y-4`}>
                <p id="tip-suspect-title" className="text-sm font-semibold text-(--app-foreground)">{t(k('suspectTitle'))}</p>
                <Text id="tip-suspect-name" field="tip_form.suspect_name" label={t(k('suspectName'))} value={form.suspect_name} onChange={(value) => set('suspect_name', value)} error={e('suspect_name')} maxLength={120} />
                <Text id="tip-suspect-unit" field="tip_form.suspect_unit" label={t(k('suspectUnit'))} value={form.suspect_unit} onChange={(value) => set('suspect_unit', value)} error={e('suspect_unit')} />
                <Text id="tip-suspect-location" field="tip_form.suspect_location" label={t(k('suspectLocation'))} value={form.suspect_location} onChange={(value) => set('suspect_location', value)} error={e('suspect_location')} />
            </div>

            <fieldset className={`${cardClass} space-y-2`} data-field="tip_form.violation_types">
                <legend className="sr-only">{t(k('violationsTitle'))}</legend>
                <p aria-hidden="true" className={labelClass}>{t(k('violationsTitle'))}<RequirementMark required={false} /></p>
                {VIOLATIONS.map((violation) => (
                    <label key={violation} className="flex min-h-11 cursor-pointer items-center gap-3">
                        <input type="checkbox" className="h-5 w-5" checked={form.violation_types.includes(violation)}
                            onChange={(event) => set('violation_types', event.target.checked ? [...form.violation_types, violation] : form.violation_types.filter((item) => item !== violation))} />
                        <span className="text-sm">{t(k(`violations.${violation}`))}</span>
                    </label>
                ))}
                {form.violation_types.includes('other') && (
                    <Text id="tip-violation-other" field="tip_form.violation_other" label={t(k('violationOther'))} value={form.violation_other} onChange={(value) => set('violation_other', value)} error={e('violation_other')} />
                )}
            </fieldset>

            <div className={cardClass} data-field="tip_form.evidence_description">
                <label htmlFor="tip-evidence" className={labelClass}>{t(k('evidenceDescription'))}<RequirementMark required={false} /></label>
                <p id="tip-evidence-hint" className={`mt-1 text-xs ${mutedClass}`}>{t(k('evidenceHint'))}</p>
                <textarea id="tip-evidence" rows={3} maxLength={2000} aria-describedby="tip-evidence-hint" value={form.evidence_description} onChange={(event) => set('evidence_description', event.target.value)} className={areaClass} />
                {e('evidence_description') && <p role="alert" className={errorClass}>{e('evidence_description')}</p>}
            </div>
        </>
    );
}

/** Section 1's choice: keep the tipster's identity confidential, or no need. */
export function TipConfidentialityChoice({ value, onChange, error }: { value: TipFormData['confidentiality']; onChange: (value: TipFormData['confidentiality']) => void; error?: string }) {
    const { t } = useLocale();
    return (
        <fieldset data-field="tip_form.confidentiality">
            <legend className={labelClass}>{t(k('confidentiality'))}<RequirementMark required={false} /></legend>
            <div className="mt-2 flex gap-3">
                {(['confidential', 'not_needed'] as const).map((choice) => (
                    <label key={choice} className="flex min-h-12 flex-1 cursor-pointer items-center gap-2 rounded-xl border border-(--app-border) px-3 has-checked:border-primary has-checked:bg-primary/5">
                        <input type="radio" name="tip-confidentiality" value={choice} checked={value === choice} onChange={() => onChange(choice)} className="h-4 w-4" />
                        <span className="text-sm">{t(k(choice === 'confidential' ? 'confidentialYes' : 'confidentialNo'))}</span>
                    </label>
                ))}
            </div>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </fieldset>
    );
}
