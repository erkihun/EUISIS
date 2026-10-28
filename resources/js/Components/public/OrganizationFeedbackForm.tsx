import { FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Building2, ChevronRight } from '@/Components/NavIcons';
import { publicButtonSecondary } from '@/Components/public/PublicPage';
import RatingScale from '@/Components/public/RatingScale';
import {
    AboutYou, EvidenceSection, FormNotice, IdentityFields, RequirementMark, TIP_TYPE_COPY, TipTypeChooser,
    areaClass, cardClass, errorClass, evidenceError, fieldClass, labelClass, mutedClass, privacyNoteKey, sendButtonClass, useSubmissionOutcome,
    type Links, type TargetDisplay,
} from '@/Components/public/feedbackFormParts';
import { FeedbackActionBar } from '@/Layouts/FeedbackAppLayout';
import { useLocale } from '@/hooks/useLocale';
import type { EvidenceLimits, SubmissionKind, SubmissionRequirements, TipType } from '@/types/publicSubmissions';

/**
 * The organization feedback form (docs/organization-public-feedback-form.md),
 * digitized from the institution's paper service-feedback questionnaire:
 *
 *   Service information → Service experience → Feedback / Tip-off / Complaint
 *   details → About you and review.
 *
 * It is a short sequence of steps on a phone, not the paper sheet squeezed
 * onto one screen. Which steps appear depends on the submission type: a
 * Feedback rates the service; a Complaint leads with what happened; a Tip-off
 * leads with the facts and treats the service as optional context. The
 * experience questions are the published questionnaire version the server
 * sent (`questionnaire`), never a list written into this page.
 *
 * One Inertia `useForm` holds every step, so moving between steps loses
 * nothing and one POST sends it all. The server stays authoritative: it
 * resolves the institution from the scanned token, re-checks every service,
 * office and answer, and its 422 errors bring the visitor back to the step
 * that holds the first one. The institution is never chosen on this page.
 */

export type QuestionType = 'rating' | 'single_choice' | 'multiple_choice' | 'short_text' | 'long_text' | 'yes_no';

export interface QuestionnaireQuestion {
    code: string;
    section: 'experience' | 'details';
    type: QuestionType;
    label: string;
    help: string | null;
    required: boolean;
    options: { code: string; label: string }[];
}

export interface Questionnaire {
    version: number;
    questions: QuestionnaireQuestion[];
}

export interface ServiceOption {
    id: string;
    code: string;
    name: string;
}

/** What the server sends for the organization form of one type. */
export interface OrganizationFormContext {
    questionnaire: Questionnaire | null;
    /** `list`: the catalog itself; `search`: too large, searched on the server; `none`: no catalog, free text. */
    serviceOptions: { mode: 'list' | 'search' | 'none'; items: ServiceOption[] };
    /** Safe public work units of the resolved organization only, each with its parent unit when that parent is itself offered. */
    offices: UnitOption[];
}

export interface UnitOption {
    id: string;
    name: string;
    type: string | null;
    parent_id: string | null;
    parent: string | null;
}

/** The unit as the visitor reads it: "Parent › Unit" when the parent is also on offer. */
function unitLabel(unit: UnitOption): string {
    return unit.parent ? `${unit.parent} › ${unit.name}` : unit.name;
}

/** Above this many units the visitor gets a filter box, as for the service catalog. */
const UNIT_FILTER_FROM = 12;

type StepId = 'service' | 'experience' | 'details' | 'submit';
/** The fields that hold one string. */
type TextField = { [K in keyof OrganizationFormData]: OrganizationFormData[K] extends string ? K : never }[keyof OrganizationFormData];
type AnswerValue = string | string[];

interface OrganizationFormData {
    form_token: string;
    questionnaire_version: string;
    service_id: string;
    service_text: string;
    organization_unit_id: string;
    unit_selection_mode: 'SELECTED' | 'GENERAL';
    service_date: string;
    service_time_band: string;
    answers: Record<string, AnswerValue>;
    rating: string;
    comment: string;
    message: string;
    tip_type: TipType | '';
    incident_date: string;
    incident_location: string;
    involved_parties: string;
    client_name: string;
    client_contact: string;
    client_email: string;
    submitter_organization: string;
    submitter_address: { city: string; sub_city: string; woreda: string; detail: string };
    attachments: File[];
}

const TIME_BANDS = ['morning', 'afternoon', 'evening'] as const;
const OTHER_SERVICE = '__other';
// The first step asks WHERE (unit), then when; an error on any of them reopens it.
const SERVICE_FIELDS = ['service_id', 'service_text', 'organization_unit_id', 'unit_selection_mode', 'service_date', 'service_time_band'];
const SUBMITTER_FIELDS = ['client_name', 'client_contact', 'client_email', 'submitter_organization', 'submitter_address', 'form_token', 'questionnaire_version'];
/** What a draft keeps for this tab: never the visitor's identity, never files, never a Tip-off. */
const DRAFT_FIELDS = ['service_id', 'service_text', 'organization_unit_id', 'unit_selection_mode', 'service_date', 'service_time_band', 'answers', 'rating', 'comment', 'message', 'incident_location'] as const;

const STEP_TITLE: Record<StepId, Record<SubmissionKind, string>> = {
    service: { feedback: 'stepService', complaint: 'stepService', suggestion: 'stepServiceOptional' },
    experience: { feedback: 'stepExperience', complaint: 'stepExperience', suggestion: 'stepExperience' },
    details: { feedback: 'stepFeedbackDetails', complaint: 'stepComplaintDetails', suggestion: 'stepTipOffDetails' },
    submit: { feedback: 'stepSubmit', complaint: 'stepSubmit', suggestion: 'stepSubmit' },
};

function isAnswered(value: AnswerValue | undefined): boolean {
    return Array.isArray(value) ? value.length > 0 : (value ?? '').trim() !== '';
}

function readDraft(key: string): Partial<OrganizationFormData> | null {
    try {
        const raw = window.sessionStorage.getItem(key);
        return raw ? (JSON.parse(raw) as Partial<OrganizationFormData>) : null;
    } catch {
        return null;
    }
}

function writeDraft(key: string, value: Partial<OrganizationFormData> | null): void {
    try {
        if (value === null) window.sessionStorage.removeItem(key);
        else window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Private mode or a full store: the form still works, only without a draft.
    }
}

/** The scanned institution, as the visitor may see it: logo and name. Never an internal id. */
function OrganizationHeader({ target }: { target: TargetDisplay }) {
    const { t } = useLocale();

    return (
        <div className={`${cardClass} flex items-center gap-3`}>
            {target.logo_url ? (
                <img src={target.logo_url} alt="" className="h-12 w-12 shrink-0 rounded-xl border border-(--app-border) bg-white object-contain p-1" />
            ) : (
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-(--app-surface-muted) text-primary" aria-hidden="true">
                    <Building2 className="h-6 w-6" />
                </span>
            )}
            <div className="min-w-0">
                <p className={`text-xs ${mutedClass}`}>{t('serviceFeedback.orgFormAbout')}</p>
                <p className="font-semibold leading-snug text-(--app-foreground) wrap-anywhere">{target.display_name}</p>
                {target.short_name && <p className={`text-sm ${mutedClass}`}>{target.short_name}</p>}
            </div>
        </div>
    );
}

/** "Step 2 of 4 · Service experience", with one segment per step — no invented percentage. */
function StepProgress({ steps, index, title }: { steps: StepId[]; index: number; title: (step: StepId) => string }) {
    const { t } = useLocale();

    return (
        <nav aria-label={t('serviceFeedback.formProgress')} className="px-1">
            <p className="text-sm font-semibold text-(--app-foreground)">
                {t('serviceFeedback.stepOf').replace('{current}', String(index + 1)).replace('{total}', String(steps.length))}
                <span className={`font-normal ${mutedClass}`}> · {title(steps[index])}</span>
            </p>
            <ol className="mt-2 flex gap-1.5">
                {steps.map((step, position) => (
                    <li key={step} aria-current={position === index ? 'step' : undefined} className={`h-1.5 flex-1 rounded-full ${position <= index ? 'bg-primary' : 'bg-(--app-border)'}`}>
                        <span className="sr-only">{title(step)}</span>
                    </li>
                ))}
            </ol>
        </nav>
    );
}

/** One non-rating questionnaire question, with native controls and its error tied to it. */
function QuestionField({ question, value, onChange, error }: { question: QuestionnaireQuestion; value: AnswerValue | undefined; onChange: (value: AnswerValue) => void; error?: string }) {
    const { t } = useLocale();
    const id = `q-${question.code}`;
    const describedBy = [question.help ? `${id}-help` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;
    const help = question.help && <p id={`${id}-help`} className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>{question.help}</p>;
    const errorLine = error && <p id={`${id}-error`} role="alert" className={errorClass}>{error}</p>;

    if (question.type === 'rating') {
        return (
            <RatingScale name={`answers.${question.code}`} legend={<>{question.label}<RequirementMark required={question.required} /></>} help={question.help} value={typeof value === 'string' ? value : ''} onChange={onChange} required={question.required} error={error} />
        );
    }

    if (question.type === 'short_text' || question.type === 'long_text') {
        const common = {
            id,
            value: typeof value === 'string' ? value : '',
            required: question.required,
            'aria-required': question.required,
            'aria-invalid': error ? true : undefined,
            'aria-describedby': describedBy,
            onChange: (event: { target: { value: string } }) => onChange(event.target.value),
        };

        return (
            <div data-field>
                <label htmlFor={id} className={labelClass}>{question.label}<RequirementMark required={question.required} /></label>
                {help}
                {question.type === 'short_text'
                    ? <input type="text" maxLength={255} className={fieldClass} {...common} />
                    : <textarea rows={4} maxLength={2000} className={areaClass} {...common} />}
                {errorLine}
            </div>
        );
    }

    const multiple = question.type === 'multiple_choice';
    const choices = question.type === 'yes_no'
        ? [{ code: 'yes', label: t('common.yes') }, { code: 'no', label: t('common.no') }]
        : question.options;
    const selected = Array.isArray(value) ? value : value ? [value] : [];

    return (
        <fieldset data-field aria-describedby={describedBy} aria-invalid={error ? true : undefined} className="min-w-0">
            <legend className={labelClass}>{question.label}<RequirementMark required={question.required} /></legend>
            {help}
            <div className={`mt-3 ${question.type === 'yes_no' ? 'grid grid-cols-2 gap-2' : 'space-y-2'}`}>
                {choices.map((choice) => {
                    const checked = selected.includes(choice.code);

                    return (
                        <label key={choice.code} className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border-2 px-3 py-2 text-base transition-colors has-focus-visible:outline-solid has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary ${checked
                            ? 'border-primary bg-(--app-surface-muted)'
                            : 'border-(--app-border-strong) bg-(--app-surface) hover:bg-(--app-surface-muted)'}`}>
                            <input
                                type={multiple ? 'checkbox' : 'radio'}
                                name={id}
                                value={choice.code}
                                checked={checked}
                                required={question.required && !multiple}
                                onChange={() => onChange(multiple
                                    ? (checked ? selected.filter((code) => code !== choice.code) : [...selected, choice.code])
                                    : choice.code)}
                                className="h-5 w-5 shrink-0 accent-primary"
                            />
                            <span className="min-w-0 text-(--app-foreground)">{choice.label}</span>
                        </label>
                    );
                })}
            </div>
            {errorLine}
        </fieldset>
    );
}

/**
 * The service received: the institution's published services (a native
 * select, filterable when long), a server search when the catalog is large,
 * or the visitor's own words when the institution has no catalog. "Not in the
 * list" always allows words instead of a wrong pick.
 */
function ServiceReceived({ mode, items, searchUrl, data, setService, error }: {
    mode: OrganizationFormContext['serviceOptions']['mode'];
    items: ServiceOption[];
    searchUrl?: string;
    data: Pick<OrganizationFormData, 'service_id' | 'service_text'>;
    setService: (serviceId: string, serviceText: string, label?: string) => void;
    error?: string;
}) {
    const { t, locale } = useLocale();
    const [other, setOther] = useState(data.service_id === '' && data.service_text !== '');
    const [filter, setFilter] = useState('');
    const [results, setResults] = useState<ServiceOption[]>([]);
    const [searching, setSearching] = useState(false);
    const [chosenLabel, setChosenLabel] = useState<string | null>(null);
    // A restored draft with the visitor's own words shows those words, never a hidden value.
    useEffect(() => {
        if (data.service_id === '' && data.service_text !== '') setOther(true);
    }, [data.service_id, data.service_text]);
    const textField = (
        <input id="service_text" type="text" maxLength={200} value={data.service_text} aria-describedby="service-text-hint" onChange={(event) => setService('', event.target.value)} className={fieldClass} />
    );

    // The server search: debounced, scoped by the scanned token, read-only. Not a submission.
    useEffect(() => {
        if (mode !== 'search' || !searchUrl || filter.trim().length < 2) {
            setResults([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setSearching(true);
            fetch(`${searchUrl}?q=${encodeURIComponent(filter.trim())}`, { headers: { Accept: 'application/json', 'X-Locale': locale }, credentials: 'same-origin', signal: controller.signal })
                .then((response) => (response.ok ? response.json() : { items: [] }))
                .then((body: { items?: ServiceOption[] }) => setResults(body.items ?? []))
                .catch(() => undefined)
                .finally(() => setSearching(false));
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [filter, mode, searchUrl, locale]);

    if (mode === 'none') {
        return (
            <div data-field>
                <label htmlFor="service_text" className={labelClass}>{t('serviceFeedback.serviceReceived')}<RequirementMark required={false} /></label>
                <p id="service-text-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.serviceReceivedFreeHint')}</p>
                {textField}
                {error && <p role="alert" className={errorClass}>{error}</p>}
            </div>
        );
    }

    const visible = filter.trim() === '' ? items : items.filter((item) => `${item.code} ${item.name}`.toLowerCase().includes(filter.trim().toLowerCase()));

    return (
        <div data-field>
            <label htmlFor={mode === 'list' ? 'service_id' : 'service_search'} className={labelClass}>{t('serviceFeedback.serviceReceived')}<RequirementMark required={false} /></label>
            {mode === 'list' && (
                <>
                    {items.length > 12 && (
                        <input type="search" aria-label={t('serviceFeedback.serviceFilter')} placeholder={t('serviceFeedback.serviceFilter')} value={filter} onChange={(event) => setFilter(event.target.value)} className={fieldClass} />
                    )}
                    <select
                        id="service_id"
                        value={other ? OTHER_SERVICE : data.service_id}
                        aria-invalid={error ? true : undefined}
                        onChange={(event) => {
                            const value = event.target.value;
                            setOther(value === OTHER_SERVICE);
                            setService(value === OTHER_SERVICE ? '' : value, '');
                        }}
                        className={fieldClass}
                    >
                        <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                        {visible.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        <option value={OTHER_SERVICE}>{t('serviceFeedback.serviceNotListed')}</option>
                    </select>
                </>
            )}
            {mode === 'search' && !other && (
                <>
                    {data.service_id !== '' ? (
                        <div className="mt-2 flex min-h-12 items-center justify-between gap-3 rounded-xl border-2 border-primary px-3 py-2">
                            <span className="min-w-0 text-base text-(--app-foreground) wrap-anywhere">{chosenLabel ?? t('serviceFeedback.serviceChosen')}</span>
                            <button type="button" onClick={() => { setService('', ''); setChosenLabel(null); }} className="min-h-11 shrink-0 rounded-full px-3 text-sm font-semibold text-primary">{t('serviceFeedback.change')}</button>
                        </div>
                    ) : (
                        <>
                            <input id="service_search" type="search" value={filter} placeholder={t('serviceFeedback.serviceSearchPlaceholder')} onChange={(event) => setFilter(event.target.value)} className={fieldClass} aria-describedby="service-search-status" />
                            <p id="service-search-status" className={`mt-1 text-xs ${mutedClass}`} aria-live="polite">
                                {searching ? t('serviceFeedback.searching') : filter.trim().length >= 2 && results.length === 0 ? t('serviceFeedback.serviceNoMatch') : ''}
                            </p>
                            {results.length > 0 && (
                                <ul className="mt-1 space-y-1.5">
                                    {results.map((item) => (
                                        <li key={item.id}>
                                            <button type="button" onClick={() => { setService(item.id, '', item.name); setChosenLabel(item.name); setFilter(''); }} className="flex min-h-12 w-full items-center rounded-xl border border-(--app-border-strong) px-3 text-left text-base hover:bg-(--app-surface-muted) focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary">
                                                {item.name}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                    <button type="button" onClick={() => { setOther(true); setService('', ''); }} className="mt-2 min-h-11 text-sm font-semibold text-primary underline underline-offset-4">{t('serviceFeedback.serviceNotListed')}</button>
                </>
            )}
            {other && (
                <div className="mt-3">
                    <label htmlFor="service_text" className={labelClass}>{t('serviceFeedback.serviceInYourWords')}</label>
                    <p id="service-text-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.serviceReceivedFreeHint')}</p>
                    {textField}
                    {mode === 'search' && (
                        <button type="button" onClick={() => { setOther(false); setService('', ''); }} className="mt-2 min-h-11 text-sm font-semibold text-primary underline underline-offset-4">{t('serviceFeedback.serviceSearchAgain')}</button>
                    )}
                </div>
            )}
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/**
 * The relevant work unit: which part of the institution the submission
 * concerns, from the public-selectable units the server sent for the scanned
 * QR, or "General / Not sure". Units are grouped under their parent when the
 * parent is itself offered; a long list gets a filter box. Only the unit's
 * name is shown: no code, no staff, no manager.
 */
function RelevantWorkUnit({ units, value, required, onChange, error }: {
    units: UnitOption[];
    value: string;
    required: boolean;
    onChange: (unitId: string) => void;
    error?: string;
}) {
    const { t } = useLocale();
    const [filter, setFilter] = useState('');
    const term = filter.trim().toLowerCase();
    const filtering = term !== '';
    const visible = filtering ? units.filter((unit) => unitLabel(unit).toLowerCase().includes(term)) : units;
    // Children sit under their parent's name; units whose parent is not on offer are listed at the top level.
    const offered = new Set(units.map((unit) => unit.id));
    const roots = visible.filter((unit) => unit.parent_id === null || !offered.has(unit.parent_id));
    const childrenOf = (parentId: string) => visible.filter((unit) => unit.parent_id === parentId);
    const groups = roots.map((root) => ({ root, children: childrenOf(root.id) }));
    const grouped = new Set(groups.flatMap((group) => [group.root.id, ...group.children.map((child) => child.id)]));
    // Deeper descendants (grandchildren) are listed by their full path so nothing on offer is hidden.
    const deeper = visible.filter((unit) => !grouped.has(unit.id));
    const selected = units.find((unit) => unit.id === value);
    const describedBy = ['relevant-work-unit-hint', error ? 'relevant-work-unit-error' : null].filter(Boolean).join(' ');

    return (
        <div data-field>
            <label htmlFor="organization_unit_id" className={labelClass}>{t('serviceFeedback.relevantWorkUnit')}<RequirementMark required={required} /></label>
            <p id="relevant-work-unit-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.relevantWorkUnitHint')}</p>
            {units.length > UNIT_FILTER_FROM && (
                <input type="search" aria-label={t('serviceFeedback.unitFilter')} placeholder={t('serviceFeedback.unitFilter')} value={filter} onChange={(event) => setFilter(event.target.value)} className={fieldClass} autoComplete="off" />
            )}
            <select
                id="organization_unit_id"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={fieldClass}
                aria-describedby={describedBy}
                aria-invalid={error ? true : undefined}
                aria-required={required || undefined}
            >
                <option value="">{t('serviceFeedback.generalNotSure')}</option>
                {/* A filtered-out selection stays in the list, so the control never shows a value it does not contain. */}
                {selected && !visible.some((unit) => unit.id === selected.id) && <option value={selected.id}>{unitLabel(selected)}</option>}
                {groups.map(({ root, children }) => (
                    children.length === 0
                        ? <option key={root.id} value={root.id}>{filtering ? unitLabel(root) : root.name}</option>
                        : (
                            <optgroup key={root.id} label={root.name}>
                                <option value={root.id}>{root.name}</option>
                                {children.map((child) => <option key={child.id} value={child.id}>{child.name}</option>)}
                            </optgroup>
                        )
                ))}
                {deeper.map((unit) => <option key={unit.id} value={unit.id}>{unitLabel(unit)}</option>)}
            </select>
            {filtering && visible.length === 0 && <p className={`mt-1 text-xs ${mutedClass}`} aria-live="polite">{t('serviceFeedback.unitNoMatch')}</p>}
            {error && <p id="relevant-work-unit-error" role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/** One line of the review: what was entered, with a button back to its step. */
function ReviewRow({ label, value, onEdit }: { label: string; value: ReactNode; onEdit: () => void }) {
    const { t } = useLocale();

    return (
        <div className="flex items-start justify-between gap-3 py-2">
            <div className="min-w-0">
                <dt className={`text-xs ${mutedClass}`}>{label}</dt>
                <dd className="mt-0.5 text-sm font-medium text-(--app-foreground) wrap-anywhere">{value}</dd>
            </div>
            <button type="button" onClick={onEdit} className="min-h-11 shrink-0 rounded-full px-3 text-sm font-semibold text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary">
                {t('serviceFeedback.change')}<span className="sr-only">: {label}</span>
            </button>
        </div>
    );
}

export default function OrganizationFeedbackForm({ kind, target, links, formToken, requirements, evidence, tipTypes, context }: {
    kind: SubmissionKind;
    target: TargetDisplay;
    links: Links;
    formToken: string | null;
    requirements: SubmissionRequirements;
    evidence: EvidenceLimits;
    tipTypes: TipType[];
    context: OrganizationFormContext;
}) {
    const { t } = useLocale();
    const formRef = useRef<HTMLFormElement>(null);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const outcome = useSubmissionOutcome(kind, formRef);
    const questions = useMemo(() => context.questionnaire?.questions ?? [], [context.questionnaire]);
    const experienceQuestions = questions.filter((question) => question.section === 'experience');
    const detailQuestions = questions.filter((question) => question.section === 'details');
    const hasExperience = kind === 'feedback' || experienceQuestions.length > 0;
    const steps: StepId[] = kind === 'feedback'
        ? ['service', 'experience', 'details', 'submit']
        : kind === 'complaint'
            ? ['service', 'details', ...(hasExperience ? ['experience' as const] : []), 'submit']
            : ['details', 'service', ...(hasExperience ? ['experience' as const] : []), 'submit'];
    const [stepIndex, setStepIndex] = useState(0);
    const step = steps[Math.min(stepIndex, steps.length - 1)];
    const [serviceLabel, setServiceLabel] = useState<string | null>(null);
    const draftKey = `addis-one:org-feedback-draft:${links.landing}:${kind}`;
    const keepsDraft = kind !== 'suggestion';

    const form = useForm<OrganizationFormData>({
        form_token: formToken ?? '',
        questionnaire_version: context.questionnaire ? String(context.questionnaire.version) : '',
        service_id: '',
        service_text: '',
        organization_unit_id: '',
        unit_selection_mode: 'GENERAL',
        service_date: '',
        service_time_band: '',
        answers: {},
        rating: '',
        comment: '',
        message: '',
        tip_type: '',
        incident_date: '',
        incident_location: '',
        involved_parties: '',
        client_name: '',
        client_contact: '',
        client_email: '',
        submitter_organization: '',
        submitter_address: { city: '', sub_city: '', woreda: '', detail: '' },
        attachments: [],
    });
    const { data, setData, errors, processing, progress, clearErrors, setError } = form;
    const fieldErrors = errors as Record<string, string | undefined>;

    // A draft of this tab's earlier attempt (not identity, not files), restored once.
    useEffect(() => {
        if (!keepsDraft) return;
        const draft = readDraft(draftKey);
        if (draft) setData((current) => ({ ...current, ...draft, answers: { ...(draft.answers ?? {}) } }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!keepsDraft) return;
        const timer = window.setTimeout(() => {
            const draft: Record<string, unknown> = {};
            DRAFT_FIELDS.forEach((field) => { draft[field] = data[field]; });
            writeDraft(draftKey, draft as Partial<OrganizationFormData>);
        }, 400);
        return () => window.clearTimeout(timer);
    }, [data, draftKey, keepsDraft]);

    // A newer questionnaire version arrived (the server refused the old one): answer that one.
    useEffect(() => {
        setData('questionnaire_version', context.questionnaire ? String(context.questionnaire.version) : '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [context.questionnaire?.version]);

    const stepTitle = (id: StepId) => t(`serviceFeedback.${STEP_TITLE[id][kind]}`);

    const goTo = (index: number) => {
        setStepIndex(index);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        window.requestAnimationFrame(() => headingRef.current?.focus({ preventScroll: true }));
    };

    const stepOf = (key: string): StepId => {
        const [base, code] = key.split('.');
        if (base === 'answers') {
            const question = questions.find((candidate) => candidate.code === code);
            return question ? (question.section === 'experience' ? 'experience' : 'details') : 'submit';
        }
        if (SERVICE_FIELDS.includes(base)) return 'service';
        if (base === 'rating') return 'experience';
        if (SUBMITTER_FIELDS.includes(base)) return 'submit';
        return 'details';
    };

    /** What the visitor must still answer on one step; the server checks again on Send. */
    const missingOn = (id: StepId): Record<string, string> => {
        const missing: Record<string, string> = {};
        const required = t('serviceFeedback.fieldRequired');
        const unanswered = (list: QuestionnaireQuestion[]) => list
            .filter((question) => question.required && !isAnswered(data.answers[question.code]))
            .forEach((question) => { missing[`answers.${question.code}`] = t('serviceFeedback.answerRequired'); });

        if (id === 'experience') {
            unanswered(experienceQuestions);
            if (kind === 'feedback' && data.rating === '') missing.rating = t('serviceFeedback.chooseOverallRating');
        }
        if (id === 'details') {
            if (kind === 'feedback' && requirements.narrative_required && data.comment.trim() === '') missing.comment = required;
            if (kind !== 'feedback' && data.message.trim() === '') missing.message = required;
            if (kind === 'suggestion' && data.tip_type === '') missing.tip_type = t('serviceFeedback.chooseTipType');
            unanswered(detailQuestions);
        }
        if (id === 'submit') {
            if (requirements.name_required && data.client_name.trim().length < 2) missing.client_name = required;
            if (requirements.phone_required && data.client_contact.trim() === '') missing.client_contact = required;
        }

        return missing;
    };

    const revealOnStep = () => {
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            const alert = formRef.current?.querySelector<HTMLElement>('[role="alert"]');
            alert?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alert?.closest<HTMLElement>('[data-field]')?.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea')?.focus({ preventScroll: true });
        }));
    };

    const next = () => {
        const missing = missingOn(step);
        if (Object.keys(missing).length > 0) {
            setError(missing as Partial<Record<keyof OrganizationFormData, string>>);
            revealOnStep();
            return;
        }
        goTo(stepIndex + 1);
    };

    /** Only what this type sends; answers only to the questions of the version shown. */
    const payload = (current: OrganizationFormData) => {
        const codes = new Set(questions.map((question) => question.code));
        const answers = Object.fromEntries(Object.entries(current.answers).filter(([code, value]) => codes.has(code) && isAnswered(value)));
        const common = {
            form_token: current.form_token,
            questionnaire_version: current.questionnaire_version,
            service_id: current.service_id,
            service_text: current.service_id === '' ? current.service_text : '',
            organization_unit_id: current.organization_unit_id,
            unit_selection_mode: current.organization_unit_id === '' ? 'GENERAL' : 'SELECTED',
            answers,
            client_name: current.client_name,
            client_contact: current.client_contact,
            client_email: current.client_email,
        };

        if (kind === 'feedback') {
            return { ...common, service_date: current.service_date, service_time_band: current.service_time_band, rating: current.rating, comment: current.comment, submitter_organization: current.submitter_organization };
        }
        if (kind === 'complaint') {
            return { ...common, service_date: current.service_date, service_time_band: current.service_time_band, message: current.message, incident_location: current.incident_location, submitter_organization: current.submitter_organization, attachments: current.attachments };
        }
        return { ...common, tip_type: current.tip_type, message: current.message, incident_date: current.incident_date, incident_location: current.incident_location, involved_parties: current.involved_parties, submitter_address: current.submitter_address, attachments: current.attachments };
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing) return;
        if (step !== 'submit') {
            next();
            return;
        }

        for (const [index, id] of steps.entries()) {
            const missing = missingOn(id);
            if (Object.keys(missing).length > 0) {
                setError(missing as Partial<Record<keyof OrganizationFormData, string>>);
                setStepIndex(index);
                revealOnStep();
                return;
            }
        }

        const url = kind === 'feedback' ? links.submitFeedback : kind === 'complaint' ? links.submitComplaint : links.submitSuggestion;
        form.transform(payload);
        form.post(url, {
            forceFormData: kind !== 'feedback',
            ...outcome(
                () => writeDraft(draftKey, null),
                (serverErrors) => {
                    const first = steps.findIndex((id) => Object.keys(serverErrors).some((key) => stepOf(key) === id));
                    if (first >= 0) setStepIndex(first);
                },
            ),
        });
    };

    const setAnswer = (code: string, value: AnswerValue) => {
        setData('answers', { ...data.answers, [code]: value });
        clearErrors(`answers.${code}` as keyof OrganizationFormData);
    };
    /** Set one text field and clear its error the moment the visitor changes it. */
    const set = (field: TextField, value: string) => {
        setData(field, value as never);
        clearErrors(field);
    };

    const formNotice = fieldErrors.form_token ?? fieldErrors.questionnaire_version ?? fieldErrors.answers ?? (kind === 'feedback' ? fieldErrors.message : undefined);
    const uploading = processing && progress !== null && progress !== undefined;
    const office = context.offices.find((candidate) => candidate.id === data.organization_unit_id);
    const serviceName = data.service_id !== ''
        ? context.serviceOptions.items.find((item) => item.id === data.service_id)?.name ?? serviceLabel ?? t('serviceFeedback.serviceChosen')
        : data.service_text.trim() !== '' ? data.service_text : null;
    const answeredExperience = experienceQuestions.filter((question) => isAnswered(data.answers[question.code])).length;
    const narrative = (kind === 'feedback' ? data.comment : data.message).trim();
    const stepIndexOf = (id: StepId) => steps.indexOf(id);

    const renderQuestion = (question: QuestionnaireQuestion) => (
        <QuestionField key={question.code} question={question} value={data.answers[question.code]} onChange={(value) => setAnswer(question.code, value)} error={fieldErrors[`answers.${question.code}`]} />
    );

    return (
        <form ref={formRef} onSubmit={submit} noValidate className="flex flex-1 flex-col">
            <div className="space-y-4 px-4 pb-6 pt-4">
                <StepProgress steps={steps} index={stepIndex} title={stepTitle} />
                <h2 ref={headingRef} tabIndex={-1} className="sr-only">{stepTitle(step)}</h2>
                <FormNotice message={formNotice} />
                {stepIndex === 0 && <OrganizationHeader target={target} />}

                {step === 'service' && (
                    <section aria-labelledby="service-step-title" className={`${cardClass} space-y-5`}>
                        <div>
                            <p id="service-step-title" className="text-base font-bold text-(--app-foreground)">{stepTitle('service')}</p>
                            <p className={`mt-1 text-sm ${mutedClass}`}>{t(kind === 'suggestion' ? 'serviceFeedback.serviceStepTipOffHint' : 'serviceFeedback.serviceStepHint')}</p>
                        </div>
                        <ServiceReceived
                            mode={context.serviceOptions.mode}
                            items={context.serviceOptions.items}
                            searchUrl={links.serviceSearch}
                            data={data}
                            setService={(serviceId, serviceText, label) => {
                                setData((current) => ({ ...current, service_id: serviceId, service_text: serviceText }));
                                clearErrors('service_id', 'service_text');
                                if (label !== undefined) setServiceLabel(label);
                            }}
                            error={fieldErrors.service_id ?? fieldErrors.service_text}
                        />
                        {context.offices.length > 0 && (
                            <RelevantWorkUnit
                                units={context.offices}
                                value={data.organization_unit_id}
                                required={kind !== 'feedback'}
                                onChange={(unitId) => {
                                    setData((current) => ({ ...current, organization_unit_id: unitId, unit_selection_mode: unitId === '' ? 'GENERAL' : 'SELECTED' }));
                                    clearErrors('organization_unit_id');
                                }}
                                error={fieldErrors.organization_unit_id}
                            />
                        )}
                        {kind !== 'suggestion' && context.offices.length > 0 && <hr className="border-(--app-border)" />}
                        {kind !== 'suggestion' && (
                            <>
                                <div data-field>
                                    <label htmlFor="service_date" className={labelClass}>{t('serviceFeedback.serviceDate')}<RequirementMark required={false} /></label>
                                    <div className="mt-2"><LocalizedDatePicker id="service_date" value={data.service_date} onChange={(iso) => set('service_date', iso)} className="min-h-12 rounded-xl text-base" /></div>
                                    {fieldErrors.service_date && <p role="alert" className={errorClass}>{fieldErrors.service_date}</p>}
                                </div>
                                <fieldset data-field className="min-w-0">
                                    <legend className={labelClass}>{t('serviceFeedback.serviceTime')}<RequirementMark required={false} /></legend>
                                    <div className="mt-3 grid grid-cols-2 gap-2 min-[360px]:grid-cols-4">
                                        {['', ...TIME_BANDS].map((band) => (
                                            <label key={band || 'unknown'} className={`flex min-h-12 cursor-pointer items-center justify-center rounded-xl border-2 px-2 text-center text-sm font-medium has-focus-visible:outline-solid has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary ${data.service_time_band === band
                                                ? 'border-primary bg-(--app-surface-muted) text-(--app-foreground)'
                                                : 'border-(--app-border-strong) text-(--app-foreground)'}`}>
                                                <input type="radio" name="service_time_band" value={band} checked={data.service_time_band === band} onChange={() => set('service_time_band', band)} className="sr-only" />
                                                {t(`serviceFeedback.timeBand_${band || 'unknown'}`)}
                                            </label>
                                        ))}
                                    </div>
                                    {fieldErrors.service_time_band && <p role="alert" className={errorClass}>{fieldErrors.service_time_band}</p>}
                                </fieldset>
                            </>
                        )}
                    </section>
                )}

                {step === 'experience' && (
                    <section aria-labelledby="experience-step-title" className="space-y-4">
                        <div className={`${cardClass} space-y-6`}>
                            <div>
                                <p id="experience-step-title" className="text-base font-bold text-(--app-foreground)">{stepTitle('experience')}</p>
                                <p className={`mt-1 text-sm ${mutedClass}`}>{t('serviceFeedback.experienceHint')}</p>
                            </div>
                            {experienceQuestions.map(renderQuestion)}
                        </div>
                        {kind === 'feedback' && (
                            <div className={cardClass}>
                                <RatingScale name="rating" emphasis legend={<>{t('serviceFeedback.overallSatisfaction')}<RequirementMark required /></>} help={t('serviceFeedback.overallSatisfactionHint')} value={data.rating} onChange={(value) => set('rating', value)} required error={fieldErrors.rating} />
                            </div>
                        )}
                    </section>
                )}

                {step === 'details' && (
                    <section aria-labelledby="details-step-title" className="space-y-4">
                        <p id="details-step-title" className="px-1 text-base font-bold text-(--app-foreground)">{stepTitle('details')}</p>
                        {kind === 'suggestion' && (
                            <TipTypeChooser tipTypes={tipTypes} value={data.tip_type} onChange={(value) => set('tip_type', value)} error={fieldErrors.tip_type} />
                        )}
                        <div className={`${cardClass} space-y-4`}>
                            {kind === 'feedback' ? (
                                <div data-field>
                                    <label htmlFor="comment" className={labelClass}>{t('serviceFeedback.orgFeedbackComment')}<RequirementMark required={requirements.narrative_required} /></label>
                                    <p id="comment-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.orgFeedbackCommentHint')}</p>
                                    <textarea id="comment" rows={5} maxLength={2000} required={requirements.narrative_required} aria-required={requirements.narrative_required} aria-describedby="comment-hint" aria-invalid={fieldErrors.comment ? true : undefined} value={data.comment} onChange={(event) => set('comment', event.target.value)} className={areaClass} />
                                    {fieldErrors.comment && <p role="alert" className={errorClass}>{fieldErrors.comment}</p>}
                                </div>
                            ) : (
                                <div data-field>
                                    <label htmlFor="entry-message" className={labelClass}>{t(kind === 'complaint' ? 'serviceFeedback.complaintWhatHappened' : 'serviceFeedback.suggestionText')}<RequirementMark required /></label>
                                    <p id="message-hint" className={`mt-1 text-xs ${mutedClass}`}>{t(kind === 'complaint' ? 'serviceFeedback.complaintWhatHappenedHint' : 'serviceFeedback.tipDetailsHint')}</p>
                                    <textarea id="entry-message" rows={7} maxLength={5000} required aria-required="true" aria-describedby="message-hint" aria-invalid={fieldErrors.message ? true : undefined} value={data.message} onChange={(event) => set('message', event.target.value)} className={areaClass} />
                                    {fieldErrors.message && <p role="alert" className={errorClass}>{fieldErrors.message}</p>}
                                </div>
                            )}
                            {kind === 'complaint' && (
                                <div data-field>
                                    <label htmlFor="incident-location" className={labelClass}>{t('serviceFeedback.incidentLocation')}<RequirementMark required={false} /></label>
                                    <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => set('incident_location', event.target.value)} className={fieldClass} />
                                    {fieldErrors.incident_location && <p role="alert" className={errorClass}>{fieldErrors.incident_location}</p>}
                                </div>
                            )}
                            {kind === 'suggestion' && (
                                <details className="group rounded-xl border border-(--app-border) p-3" open={Boolean(fieldErrors.incident_date || fieldErrors.incident_location || fieldErrors.involved_parties)}>
                                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold text-(--app-foreground)">
                                        {t('serviceFeedback.tipMoreDetails')}
                                        <ChevronRight className="h-5 w-5 transition-transform group-open:rotate-90" aria-hidden="true" />
                                    </summary>
                                    <div className="mt-3 space-y-4">
                                        <div data-field>
                                            <label htmlFor="incident-date" className={labelClass}>{t('serviceFeedback.tipIncidentDate')}</label>
                                            <div className="mt-2"><LocalizedDatePicker id="incident-date" value={data.incident_date} onChange={(iso) => set('incident_date', iso)} className="min-h-12 rounded-xl text-base" /></div>
                                            {fieldErrors.incident_date && <p role="alert" className={errorClass}>{fieldErrors.incident_date}</p>}
                                        </div>
                                        <div data-field>
                                            <label htmlFor="incident-location" className={labelClass}>{t('serviceFeedback.tipIncidentLocation')}</label>
                                            <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => set('incident_location', event.target.value)} className={fieldClass} />
                                            {fieldErrors.incident_location && <p role="alert" className={errorClass}>{fieldErrors.incident_location}</p>}
                                        </div>
                                        <div data-field>
                                            <label htmlFor="involved-parties" className={labelClass}>{t('serviceFeedback.tipInvolvedParties')}</label>
                                            <p id="involved-parties-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.tipInvolvedPartiesHint')}</p>
                                            <textarea id="involved-parties" aria-describedby="involved-parties-hint" maxLength={2000} rows={3} value={data.involved_parties} onChange={(event) => set('involved_parties', event.target.value)} className={areaClass} />
                                            {fieldErrors.involved_parties && <p role="alert" className={errorClass}>{fieldErrors.involved_parties}</p>}
                                        </div>
                                    </div>
                                </details>
                            )}
                            {detailQuestions.map(renderQuestion)}
                        </div>
                        {kind !== 'feedback' && requirements.evidence_allowed && (
                            <EvidenceSection files={data.attachments} onChange={(files) => { setData('attachments', files); clearErrors(); }} limits={evidence} error={evidenceError(fieldErrors)} />
                        )}
                    </section>
                )}

                {step === 'submit' && (
                    <section aria-labelledby="submit-step-title" className="space-y-4">
                        <p id="submit-step-title" className="px-1 text-base font-bold text-(--app-foreground)">{stepTitle('submit')}</p>
                        {kind === 'suggestion' ? (
                            <div role="group" aria-labelledby="tipster-title" className={`${cardClass} space-y-4`}>
                                <div>
                                    <p id="tipster-title" className="text-sm font-semibold text-(--app-foreground)">{t('serviceFeedback.tipsterTitle')}</p>
                                    <p className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>{t('serviceFeedback.tipsterHint')}</p>
                                </div>
                                <IdentityFields requirements={requirements} data={{ client_name: data.client_name, client_contact: data.client_contact, client_email: data.client_email }} set={(field, value) => set(field, value)} errors={fieldErrors} />
                                <details className="group rounded-xl border border-(--app-border) p-3">
                                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold text-(--app-foreground)">
                                        {t('serviceFeedback.tipsterAddressToggle')}
                                        <ChevronRight className="h-5 w-5 transition-transform group-open:rotate-90" aria-hidden="true" />
                                    </summary>
                                    <div className="mt-3 space-y-4">
                                        {(['city', 'sub_city', 'woreda', 'detail'] as const).map((part) => (
                                            <div key={part} data-field>
                                                <label htmlFor={`address-${part}`} className={labelClass}>{t(`serviceFeedback.${{ city: 'addressCity', sub_city: 'addressSubCity', woreda: 'addressWoreda', detail: 'addressDetail' }[part]}`)}</label>
                                                <input id={`address-${part}`} type="text" autoComplete={part === 'city' ? 'address-level2' : 'off'} maxLength={part === 'detail' ? 255 : 100} value={data.submitter_address[part]} onChange={(event) => setData('submitter_address', { ...data.submitter_address, [part]: event.target.value })} className={fieldClass} />
                                                {fieldErrors[`submitter_address.${part}`] && <p role="alert" className={errorClass}>{fieldErrors[`submitter_address.${part}`]}</p>}
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            </div>
                        ) : (
                            <AboutYou requirements={requirements} data={{ client_name: data.client_name, client_contact: data.client_contact, client_email: data.client_email, submitter_organization: data.submitter_organization }} set={(field, value) => set(field, value)} errors={fieldErrors} />
                        )}

                        <div className={cardClass}>
                            <p className="text-sm font-semibold text-(--app-foreground)">{t('serviceFeedback.reviewTitle')}</p>
                            <dl className="mt-1 divide-y divide-(--app-border)">
                                {kind === 'suggestion' && (
                                    <ReviewRow label={t('serviceFeedback.tipTypeLabel')} value={data.tip_type ? t(TIP_TYPE_COPY[data.tip_type].label) : '—'} onEdit={() => goTo(stepIndexOf('details'))} />
                                )}
                                <ReviewRow label={t('serviceFeedback.serviceReceived')} value={serviceName ?? t('serviceFeedback.notGiven')} onEdit={() => goTo(stepIndexOf('service'))} />
                                {context.offices.length > 0 && (
                                    <ReviewRow label={t('serviceFeedback.relevantWorkUnit')} value={office ? unitLabel(office) : t('serviceFeedback.generalNotSure')} onEdit={() => goTo(stepIndexOf('service'))} />
                                )}
                                {kind !== 'suggestion' && data.service_date !== '' && (
                                    <ReviewRow label={t('serviceFeedback.serviceDate')} value={<LocalizedDateDisplay value={data.service_date} />} onEdit={() => goTo(stepIndexOf('service'))} />
                                )}
                                {hasExperience && experienceQuestions.length > 0 && (
                                    <ReviewRow label={t('serviceFeedback.stepExperience')} value={t('serviceFeedback.answeredOf').replace('{answered}', String(answeredExperience)).replace('{total}', String(experienceQuestions.length))} onEdit={() => goTo(stepIndexOf('experience'))} />
                                )}
                                {kind === 'feedback' && (
                                    <ReviewRow label={t('serviceFeedback.overallSatisfaction')} value={data.rating !== '' ? t(`serviceFeedback.rating${data.rating}`) : '—'} onEdit={() => goTo(stepIndexOf('experience'))} />
                                )}
                                <ReviewRow label={stepTitle('details')} value={narrative !== '' ? (narrative.length > 140 ? `${narrative.slice(0, 140)}…` : narrative) : '—'} onEdit={() => goTo(stepIndexOf('details'))} />
                                {data.attachments.length > 0 && (
                                    <ReviewRow label={t('serviceFeedback.evidenceTitle')} value={t('serviceFeedback.filesAttached').replace('{count}', String(data.attachments.length))} onEdit={() => goTo(stepIndexOf('details'))} />
                                )}
                            </dl>
                        </div>
                        <p className={`px-1 text-center text-xs leading-relaxed ${mutedClass}`}>{t(privacyNoteKey(requirements))}</p>
                    </section>
                )}
            </div>

            <FeedbackActionBar>
                {uploading && (
                    <div className="mb-2" role="status" aria-live="polite">
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-(--app-surface-muted)">
                            <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${progress?.percentage ?? 0}%` }} />
                        </div>
                        <p className={`mt-1 text-center text-xs ${mutedClass}`}>{t('serviceFeedback.uploadProgress').replace('{percent}', String(progress?.percentage ?? 0))}</p>
                    </div>
                )}
                <div className="flex gap-2">
                    {stepIndex > 0 && (
                        <button type="button" onClick={() => goTo(stepIndex - 1)} disabled={processing} className={`${publicButtonSecondary} min-h-[52px] shrink-0 rounded-xl px-5 text-base`}>
                            {t('serviceFeedback.stepBack')}
                        </button>
                    )}
                    <button type="submit" disabled={processing} className={`${sendButtonClass} flex-1`}>
                        {step !== 'submit'
                            ? t('serviceFeedback.stepNext')
                            : processing ? t('serviceFeedback.submitting') : t(kind === 'feedback' ? 'serviceFeedback.submitFeedback' : kind === 'complaint' ? 'serviceFeedback.submitComplaint' : 'serviceFeedback.submitSuggestion')}
                    </button>
                </div>
            </FeedbackActionBar>
        </form>
    );
}
