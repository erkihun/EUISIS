import { useEffect, useId, useMemo, useState } from 'react';
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
    /** Chosen from the scanned institution's own units and staff (the server checks both). */
    suspect_unit_id: string;
    suspect_employee_id: string;
    /** No longer asked: the scanned QR already names the institution. Kept for drafts saved before. */
    suspect_location: string;
    violation_types: Violation[];
    violation_other: string;
    evidence_description: string;
};

export const emptyTipForm = (): TipFormData => ({
    confidentiality: '', suspect_name: '', suspect_unit: '', suspect_unit_id: '', suspect_employee_id: '', suspect_location: '', violation_types: [], violation_other: '', evidence_description: '',
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

type UnitOption = { id: string; name: string; parent?: string | null };
type PickOption = { id: string; label: string; detail?: string | null; photo?: string | null; withPhoto?: boolean };

/** A staff member's photo, or their initials when there is none (or it fails to load). */
function StaffAvatar({ name, url }: { name: string; url?: string | null }) {
    const [failed, setFailed] = useState(false);
    const initials = name.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('');
    return url && !failed
        ? <img src={url} alt="" loading="lazy" decoding="async" onError={() => setFailed(true)} className="h-10 w-10 shrink-0 rounded-full border border-(--app-border) object-cover" />
        : <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-(--app-surface-muted) text-sm font-semibold text-(--app-muted-foreground)">{initials}</span>;
}

/**
 * A searchable field: type to narrow the list, tap a row to choose. Typing
 * after a choice clears it, so what is shown is always what is sent. Large
 * rows for a phone; the list stays inside the card on any screen.
 */
function SearchPick({ id, label, hint, text, chosen, options, loading, onType, onChoose, emptyText, disabled, error, field }: {
    id: string; label: string; hint?: string; text: string; chosen: boolean; options: PickOption[]; loading?: boolean;
    onType: (value: string) => void; onChoose: (option: PickOption) => void; emptyText: string; disabled?: boolean; error?: string; field: string;
}) {
    const listId = useId();
    const [open, setOpen] = useState(false);
    // Nothing chosen yet: the list is already shown, so the visitor sees what there is without a tap.
    const show = !disabled && (open || (!chosen && text === ''));

    return (
        <div data-field={field} className="relative">
            <label htmlFor={id} className={labelClass}>{label}<RequirementMark required={false} /></label>
            {hint && <p id={`${id}-hint`} className={`mt-1 text-xs ${mutedClass}`}>{hint}</p>}
            <input id={id} type="search" role="combobox" aria-expanded={show} aria-controls={listId} aria-autocomplete="list" autoComplete="off" maxLength={120}
                aria-describedby={hint ? `${id}-hint` : undefined} aria-invalid={error ? true : undefined} disabled={disabled}
                value={text} onFocus={() => setOpen(true)} onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onChange={(event) => { onType(event.target.value); setOpen(true); }}
                className={`${fieldClass} ${chosen ? 'border-primary' : ''} disabled:opacity-60`} />
            {show && (
                <ul id={listId} role="listbox" className="mt-1 max-h-60 overflow-y-auto rounded-xl border border-(--app-border) bg-(--app-surface) shadow-sm">
                    {loading && <li className={`px-4 py-3 text-sm ${mutedClass}`}>…</li>}
                    {!loading && options.length === 0 && <li className={`px-4 py-3 text-sm ${mutedClass}`}>{emptyText}</li>}
                    {!loading && options.map((option) => (
                        <li key={option.id} role="option" aria-selected={false}>
                            <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => { onChoose(option); setOpen(false); }}
                                className="flex min-h-12 w-full items-center gap-3 px-4 py-2 text-left hover:bg-(--app-surface-muted) focus-visible:bg-(--app-surface-muted) focus-visible:outline-hidden">
                                {option.withPhoto && <StaffAvatar name={option.label} url={option.photo} />}
                                <span className="flex min-w-0 flex-col items-start">
                                    <span className="text-sm font-medium text-(--app-foreground) wrap-anywhere">{option.label}</span>
                                    {option.detail && <span className={`text-xs ${mutedClass}`}>{option.detail}</span>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/**
 * The person concerned, on an institution QR (owner request 2026-11-08):
 * the scanned QR names the institution, so no sub-city / woreda is asked;
 * the work unit is searched among the institution's own units (starting
 * from the one chosen earlier), and the person among that unit's staff only.
 */
function TipSuspectPickers({ form, set, errors, units, staffUrl, defaultUnitId }: { form: TipFormData; set: Setter; errors: Errors; units: UnitOption[]; staffUrl: string; defaultUnitId?: string }) {
    const { t } = useLocale();
    const e = (key: string) => errors[`tip_form.${key}`];
    const [staff, setStaff] = useState<PickOption[]>([]);
    const [loading, setLoading] = useState(false);

    // Start from the work unit chosen on the earlier step.
    useEffect(() => {
        if (form.suspect_unit_id === '' && form.suspect_unit === '' && defaultUnitId) {
            const unit = units.find((candidate) => candidate.id === defaultUnitId);
            if (unit) {
                set('suspect_unit_id', unit.id);
                set('suspect_unit', unit.name);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [defaultUnitId]);

    const unitOptions = useMemo(() => {
        const term = form.suspect_unit_id === '' ? form.suspect_unit.trim().toLowerCase() : '';
        return units.filter((unit) => term === '' || unit.name.toLowerCase().includes(term) || (unit.parent ?? '').toLowerCase().includes(term))
            .slice(0, 200).map((unit) => ({ id: unit.id, label: unit.name, detail: unit.parent ?? null }));
    }, [units, form.suspect_unit, form.suspect_unit_id]);

    // The chosen unit's staff, searched as the visitor types (debounced, read-only).
    useEffect(() => {
        if (form.suspect_unit_id === '') {
            setStaff([]);
            return;
        }
        const controller = new AbortController();
        const term = form.suspect_employee_id === '' ? form.suspect_name.trim() : '';
        const timer = window.setTimeout(() => {
            setLoading(true);
            fetch(`${staffUrl}?${new URLSearchParams({ unit: form.suspect_unit_id, q: term })}`, { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : { items: [] }))
                .then((body: { items: { id: string; name: string; position: string | null; photo_url?: string | null }[] }) => setStaff(body.items.map((item) => ({ id: item.id, label: item.name, detail: item.position, photo: item.photo_url ?? null, withPhoto: true }))))
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 250);
        return () => { controller.abort(); window.clearTimeout(timer); };
    }, [staffUrl, form.suspect_unit_id, form.suspect_name, form.suspect_employee_id]);

    return (
        <>
            <SearchPick id="tip-suspect-unit" field="tip_form.suspect_unit_id" label={t(k('suspectUnit'))} hint={t(k('suspectUnitHint'))}
                text={form.suspect_unit} chosen={form.suspect_unit_id !== ''} options={unitOptions} emptyText={t(k('noMatch'))} error={e('suspect_unit_id') ?? e('suspect_unit')}
                onType={(value) => { set('suspect_unit', value); set('suspect_unit_id', ''); set('suspect_employee_id', ''); }}
                onChoose={(option) => { set('suspect_unit', option.label); set('suspect_unit_id', option.id); set('suspect_employee_id', ''); set('suspect_name', ''); }} />
            <SearchPick id="tip-suspect-name" field="tip_form.suspect_employee_id" label={t(k('suspectName'))}
                hint={form.suspect_unit_id === '' ? t(k('suspectPickUnitFirst')) : t(k('suspectNameHint'))}
                text={form.suspect_name} chosen={form.suspect_employee_id !== ''} options={staff} loading={loading} emptyText={t(k('noStaffMatch'))}
                disabled={form.suspect_unit_id === ''} error={e('suspect_employee_id') ?? e('suspect_name')}
                onType={(value) => { set('suspect_name', value); set('suspect_employee_id', ''); }}
                onChoose={(option) => { set('suspect_name', option.label); set('suspect_employee_id', option.id); }} />
        </>
    );
}

/** Section 2: the person suspected, and section 3: the violations alleged and the supporting evidence. */
export function TipSuspectAndViolationFields({ form, set, errors, units, staffUrl, defaultUnitId }: { form: TipFormData; set: Setter; errors: Errors; units?: UnitOption[]; staffUrl?: string; defaultUnitId?: string }) {
    const { t } = useLocale();
    const e = (key: string) => errors[`tip_form.${key}`];
    return (
        <>
            <div role="group" aria-labelledby="tip-suspect-title" className={`${cardClass} space-y-4`}>
                <p id="tip-suspect-title" className="text-sm font-semibold text-(--app-foreground)">{t(k('suspectTitle'))}</p>
                {units && units.length > 0 && staffUrl
                    ? <TipSuspectPickers form={form} set={set} errors={errors} units={units} staffUrl={staffUrl} defaultUnitId={defaultUnitId} />
                    : <>
                        <Text id="tip-suspect-name" field="tip_form.suspect_name" label={t(k('suspectName'))} value={form.suspect_name} onChange={(value) => set('suspect_name', value)} error={e('suspect_name')} maxLength={120} />
                        <Text id="tip-suspect-unit" field="tip_form.suspect_unit" label={t(k('suspectUnit'))} value={form.suspect_unit} onChange={(value) => set('suspect_unit', value)} error={e('suspect_unit')} />
                    </>}
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
