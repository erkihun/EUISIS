import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { RequirementMark, areaClass, cardClass, errorClass, fieldClass, labelClass, mutedClass } from '@/Components/public/feedbackFormParts';
import { useLocale } from '@/hooks/useLocale';

/**
 * The public complaint form's sections (owner's template, 2026-11-08;
 * server rules in PublicComplaintForm). Shared by the employee-QR and the
 * institution-QR complaint forms:
 *
 *  - ComplainantFields: section 1 beyond name and phone (sex, identity
 *    document, address, other phone, representation);
 *  - ComplaintMatterFields: sections 2–4 (the unit or person complained
 *    about, the service, where it was heard before and the decision, the
 *    remedy sought, the documents attached);
 *  - DeclarationField: the declaration that replaces the paper signature.
 *
 * The body complained about (section 2's institution) is the QR code's target
 * and is shown on the form's target card, so it is never typed.
 */

export const ID_TYPES = ['residence_id', 'national_id', 'passport', 'driving_licence', 'other'] as const;
export const ATTACHED = ['id_copy', 'decision_copy', 'poa_copy'] as const;

export type ComplaintFormData = {
    gender: '' | 'male' | 'female';
    id_type: '' | (typeof ID_TYPES)[number];
    id_number: string;
    alt_phone: string;
    represented: boolean;
    principal_name: string;
    poa_reference: string;
    poa_date: string;
    unit_or_staff: string;
    service_type: string;
    prior_woreda_date: string;
    prior_woreda_decision: string;
    prior_sub_city_date: string;
    prior_sub_city_decision: string;
    remedy_sought: string;
    attached: (typeof ATTACHED)[number][];
    other_pages: string;
    declaration: boolean;
};

export type ComplainantAddress = { city: string; sub_city: string; woreda: string; house_number: string; detail: string };

export const emptyComplaintForm = (): ComplaintFormData => ({
    gender: '', id_type: '', id_number: '', alt_phone: '', represented: false, principal_name: '', poa_reference: '', poa_date: '',
    unit_or_staff: '', service_type: '', prior_woreda_date: '', prior_woreda_decision: '', prior_sub_city_date: '', prior_sub_city_decision: '',
    remedy_sought: '', attached: [], other_pages: '', declaration: false,
});

export const emptyComplainantAddress = (): ComplainantAddress => ({ city: '', sub_city: '', woreda: '', house_number: '', detail: '' });

type Errors = Record<string, string | undefined>;
const c = (key: string) => `serviceFeedback.complaintForm.${key}`;

/** What the browser must still fill in before sending (the server checks again). */
export function missingComplaintFields(form: ComplaintFormData, address: ComplainantAddress, required: string, part: 'complainant' | 'matter' | 'all' = 'all'): Record<string, string> {
    const missing: Record<string, string> = {};
    if (part !== 'matter') {
        if (form.gender === '') missing['complaint_form.gender'] = required;
        if (form.id_type === '') missing['complaint_form.id_type'] = required;
        if (form.id_number.trim() === '') missing['complaint_form.id_number'] = required;
        if (address.sub_city.trim() === '') missing['submitter_address.sub_city'] = required;
        if (address.woreda.trim() === '') missing['submitter_address.woreda'] = required;
        if (form.represented && form.principal_name.trim() === '') missing['complaint_form.principal_name'] = required;
        if (form.represented && form.poa_reference.trim() === '') missing['complaint_form.poa_reference'] = required;
        if (!form.declaration) missing['complaint_form.declaration'] = required;
    }
    if (part !== 'complainant' && form.remedy_sought.trim() === '') missing['complaint_form.remedy_sought'] = required;
    return missing;
}

function Text({ id, field, label, value, onChange, error, required = false, maxLength = 120, hint, type = 'text', inputMode }: {
    id: string; field: string; label: string; value: string; onChange: (value: string) => void; error?: string; required?: boolean; maxLength?: number; hint?: string;
    type?: string; inputMode?: 'tel' | 'numeric' | 'text';
}) {
    return (
        <div data-field={field}>
            <label htmlFor={id} className={labelClass}>{label}<RequirementMark required={required} /></label>
            {hint && <p id={`${id}-hint`} className={`mt-1 text-xs ${mutedClass}`}>{hint}</p>}
            <input id={id} type={type} inputMode={inputMode} maxLength={maxLength} required={required} aria-required={required || undefined} aria-invalid={error ? true : undefined}
                aria-describedby={hint ? `${id}-hint` : undefined} value={value} onChange={(event) => onChange(event.target.value)} className={fieldClass} />
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/** Section 1: who is complaining, beyond the name and phone asked with every submission. */
export function ComplainantFields({ form, set, address, setAddress, errors }: {
    form: ComplaintFormData; set: <K extends keyof ComplaintFormData>(field: K, value: ComplaintFormData[K]) => void;
    address: ComplainantAddress; setAddress: (address: ComplainantAddress) => void; errors: Errors;
}) {
    const { t } = useLocale();
    const e = (key: string) => errors[`complaint_form.${key}`];
    return (
        <div role="group" aria-labelledby="complainant-title" className={`${cardClass} space-y-4`}>
            <p id="complainant-title" className="text-sm font-semibold text-(--app-foreground)">{t(c('complainantTitle'))}</p>
            <fieldset data-field="complaint_form.gender">
                <legend className={labelClass}>{t(c('gender'))}<RequirementMark required /></legend>
                <div className="mt-2 flex gap-3">
                    {(['male', 'female'] as const).map((value) => (
                        <label key={value} className="flex min-h-12 flex-1 cursor-pointer items-center gap-2 rounded-xl border border-(--app-border) px-3 has-checked:border-primary has-checked:bg-primary/5">
                            <input type="radio" name="complaint-gender" value={value} checked={form.gender === value} onChange={() => set('gender', value)} required className="h-4 w-4" />
                            <span className="text-sm">{t(c(value))}</span>
                        </label>
                    ))}
                </div>
                {e('gender') && <p role="alert" className={errorClass}>{e('gender')}</p>}
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
                <div data-field="complaint_form.id_type">
                    <label htmlFor="complaint-id-type" className={labelClass}>{t(c('idType'))}<RequirementMark required /></label>
                    <select id="complaint-id-type" required value={form.id_type} onChange={(event) => set('id_type', event.target.value as ComplaintFormData['id_type'])} className={fieldClass} aria-invalid={e('id_type') ? true : undefined}>
                        <option value="">{t(c('choose'))}</option>
                        {ID_TYPES.map((value) => <option key={value} value={value}>{t(c(`idTypes.${value}`))}</option>)}
                    </select>
                    {e('id_type') && <p role="alert" className={errorClass}>{e('id_type')}</p>}
                </div>
                <Text id="complaint-id-number" field="complaint_form.id_number" label={t(c('idNumber'))} value={form.id_number} onChange={(value) => set('id_number', value)} error={e('id_number')} required maxLength={60} />
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                <Text id="complaint-sub-city" field="submitter_address.sub_city" label={t(c('subCity'))} value={address.sub_city} onChange={(value) => setAddress({ ...address, sub_city: value })} error={errors['submitter_address.sub_city']} required maxLength={100} />
                <Text id="complaint-woreda" field="submitter_address.woreda" label={t(c('woreda'))} value={address.woreda} onChange={(value) => setAddress({ ...address, woreda: value })} error={errors['submitter_address.woreda']} required maxLength={100} />
                <Text id="complaint-house" field="submitter_address.house_number" label={t(c('houseNumber'))} value={address.house_number} onChange={(value) => setAddress({ ...address, house_number: value })} error={errors['submitter_address.house_number']} maxLength={30} />
            </div>
            <Text id="complaint-alt-phone" field="complaint_form.alt_phone" label={t(c('altPhone'))} value={form.alt_phone} onChange={(value) => set('alt_phone', value)} error={e('alt_phone')} maxLength={40} type="tel" inputMode="tel" />
            <div data-field="complaint_form.represented" className="rounded-xl border border-(--app-border) p-3">
                <label className="flex min-h-11 cursor-pointer items-center gap-3">
                    <input type="checkbox" checked={form.represented} onChange={(event) => set('represented', event.target.checked)} className="h-5 w-5" />
                    <span className="text-sm font-semibold text-(--app-foreground)">{t(c('represented'))}</span>
                </label>
                {form.represented && (
                    <div className="mt-3 space-y-4">
                        <Text id="complaint-principal" field="complaint_form.principal_name" label={t(c('principalName'))} value={form.principal_name} onChange={(value) => set('principal_name', value)} error={e('principal_name')} required />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Text id="complaint-poa" field="complaint_form.poa_reference" label={t(c('poaReference'))} value={form.poa_reference} onChange={(value) => set('poa_reference', value)} error={e('poa_reference')} required maxLength={100} />
                            <div data-field="complaint_form.poa_date">
                                <label htmlFor="complaint-poa-date" className={labelClass}>{t(c('poaDate'))}</label>
                                <div className="mt-2"><LocalizedDatePicker id="complaint-poa-date" value={form.poa_date} onChange={(iso) => set('poa_date', iso)} className="min-h-12 rounded-xl text-base" /></div>
                                {e('poa_date') && <p role="alert" className={errorClass}>{e('poa_date')}</p>}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

/** Sections 2–4: who is complained about, the matter and the documents attached. */
export function ComplaintMatterFields({ form, set, errors, askServiceType = true }: {
    form: ComplaintFormData; set: <K extends keyof ComplaintFormData>(field: K, value: ComplaintFormData[K]) => void; errors: Errors;
    /** The institution form already asks for the service received. */
    askServiceType?: boolean;
}) {
    const { t } = useLocale();
    const e = (key: string) => errors[`complaint_form.${key}`];
    const prior = (level: 'woreda' | 'sub_city') => (
        <div className="grid gap-3 sm:grid-cols-[minmax(0,12rem)_1fr]">
            <div data-field={`complaint_form.prior_${level}_date`}>
                <label htmlFor={`complaint-prior-${level}-date`} className={labelClass}>{t(c(level === 'woreda' ? 'priorWoredaDate' : 'priorSubCityDate'))}</label>
                <div className="mt-2"><LocalizedDatePicker id={`complaint-prior-${level}-date`} value={form[`prior_${level}_date`]} onChange={(iso) => set(`prior_${level}_date`, iso)} className="min-h-12 rounded-xl text-base" /></div>
                {e(`prior_${level}_date`) && <p role="alert" className={errorClass}>{e(`prior_${level}_date`)}</p>}
            </div>
            <div data-field={`complaint_form.prior_${level}_decision`}>
                <label htmlFor={`complaint-prior-${level}-decision`} className={labelClass}>{t(c('priorDecision'))}</label>
                <textarea id={`complaint-prior-${level}-decision`} rows={2} maxLength={1000} value={form[`prior_${level}_decision`]} onChange={(event) => set(`prior_${level}_decision`, event.target.value)} className={areaClass} />
                {e(`prior_${level}_decision`) && <p role="alert" className={errorClass}>{e(`prior_${level}_decision`)}</p>}
            </div>
        </div>
    );

    return (
        <>
            <div role="group" aria-labelledby="complaint-against-title" className={`${cardClass} space-y-4`}>
                <p id="complaint-against-title" className="text-sm font-semibold text-(--app-foreground)">{t(c('againstTitle'))}</p>
                <Text id="complaint-unit-staff" field="complaint_form.unit_or_staff" label={t(c('unitOrStaff'))} hint={t(c('unitOrStaffHint'))} value={form.unit_or_staff} onChange={(value) => set('unit_or_staff', value)} error={e('unit_or_staff')} maxLength={200} />
                {askServiceType && <Text id="complaint-service-type" field="complaint_form.service_type" label={t(c('serviceType'))} hint={t(c('serviceTypeHint'))} value={form.service_type} onChange={(value) => set('service_type', value)} error={e('service_type')} maxLength={200} />}
            </div>

            <div role="group" aria-labelledby="complaint-prior-title" className={`${cardClass} space-y-4`}>
                <div>
                    <p id="complaint-prior-title" className="text-sm font-semibold text-(--app-foreground)">{t(c('priorTitle'))}</p>
                    <p className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>{t(c('priorNote'))}</p>
                </div>
                {prior('woreda')}
                {prior('sub_city')}
            </div>

            <div className={cardClass} data-field="complaint_form.remedy_sought">
                <label htmlFor="complaint-remedy" className={labelClass}>{t(c('remedySought'))}<RequirementMark required /></label>
                <textarea id="complaint-remedy" rows={3} maxLength={2000} required aria-required="true" aria-invalid={e('remedy_sought') ? true : undefined} value={form.remedy_sought} onChange={(event) => set('remedy_sought', event.target.value)} className={areaClass} />
                {e('remedy_sought') && <p role="alert" className={errorClass}>{e('remedy_sought')}</p>}
            </div>

            <fieldset className={`${cardClass} space-y-3`} data-field="complaint_form.attached">
                <legend className="sr-only">{t(c('attachedTitle'))}</legend>
                <p aria-hidden="true" className="text-sm font-semibold text-(--app-foreground)">{t(c('attachedTitle'))}</p>
                <p className={`text-xs ${mutedClass}`}>{t(c('attachedHint'))}</p>
                {ATTACHED.map((value) => (
                    <label key={value} className="flex min-h-11 cursor-pointer items-center gap-3">
                        <input type="checkbox" className="h-5 w-5" checked={form.attached.includes(value)}
                            onChange={(event) => set('attached', event.target.checked ? [...form.attached, value] : form.attached.filter((item) => item !== value))} />
                        <span className="text-sm">{t(c(`attachedTypes.${value}`))}</span>
                    </label>
                ))}
                <Text id="complaint-other-pages" field="complaint_form.other_pages" label={t(c('otherPages'))} value={form.other_pages} onChange={(value) => set('other_pages', value.replace(/\D/g, '').slice(0, 3))} error={e('other_pages')} maxLength={3} inputMode="numeric" />
            </fieldset>
        </>
    );
}

/** The declaration that what is stated and attached is true (in place of the paper signature). */
export function DeclarationField({ checked, onChange, error }: { checked: boolean; onChange: (value: boolean) => void; error?: string }) {
    const { t } = useLocale();
    return (
        <div className={cardClass} data-field="complaint_form.declaration">
            <p className="text-sm font-semibold text-(--app-foreground)">{t(c('declarationTitle'))}</p>
            <label className="mt-2 flex cursor-pointer items-start gap-3">
                <input type="checkbox" required aria-required="true" aria-invalid={error ? true : undefined} checked={checked} onChange={(event) => onChange(event.target.checked)} className="mt-0.5 h-5 w-5 shrink-0" />
                <span className="text-sm leading-relaxed">{t(c('declaration'))}</span>
            </label>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}
