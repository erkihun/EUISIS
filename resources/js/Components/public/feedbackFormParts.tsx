import { RefObject, useEffect, useRef } from 'react';
import { controlClassName } from '@euisis/ui';
import type { Page, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { publicButtonPrimary } from '@/Components/public/PublicPage';
import EvidencePicker from '@/Components/public/EvidencePicker';
import { AlertTriangle, MessageSquareIcon, StarIcon as StarOutlineIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import type { EvidenceLimits, SubmissionKind, SubmissionRequirements, TipType } from '@/types/publicSubmissions';

/**
 * The building blocks every public QR form shares — the employee QR forms of
 * Public/ServiceFeedback and the organization feedback form — so the look,
 * the identity fields, the Tip-off type chooser, the evidence picker and what
 * the visitor is told after pressing Send are the same everywhere.
 */

/** Everything the public page is permitted to know about the target. */
export interface TargetDisplay {
    target_type: 'employee' | 'organization';
    /** The employee's full name, or the institution's name. */
    display_name: string;
    /** Employee: the title of the post they hold now (job designation); null when they hold none. */
    position_title?: string | null;
    /** Employee: a short-lived signed link to the photo; null when there is none. */
    photo_url?: string | null;
    /** Institution only. */
    logo_url?: string | null;
    short_name?: string | null;
}

export interface Links {
    landing: string;
    feedback: string;
    suggestion: string;
    complaint: string;
    submitFeedback: string;
    submitSuggestion: string;
    submitComplaint: string;
    /** Organization QR only: search of the institution's services when its catalog is large. */
    serviceSearch?: string;
}

export interface Receipt {
    type: SubmissionKind;
    target_type: 'employee' | 'organization';
    target_name: string;
    reference: string | null;
    tip_type: TipType | null;
    submitted_at: string | null;
}

/** An issue category offered for this submission type, in the visitor's language: what kind of issue it is, never where it belongs. */
export interface CategoryOption {
    id: string;
    name: string;
    /** Short helper under the name, e.g. "Service took longer than expected."; null when none is configured. */
    description: string | null;
}

// ── Look ────────────────────────────────────────────────────────────────────
// Grouped cards on a soft background, 16px type in every control (no zoom on
// focus in iOS Safari), 48px+ touch targets.

export const errorClass = 'mt-2 text-sm text-red-600 dark:text-red-400';
export const cardClass = 'rounded-2xl border border-(--app-border) bg-(--app-surface) p-4';
export const labelClass = 'block text-sm font-semibold text-(--app-foreground)';
export const optionalClass = 'ml-1 font-normal text-(--app-muted-foreground)';
export const mutedClass = 'text-(--app-muted-foreground)';
// min-h, not h: the shared control sets its own height and would win over a plain h-12.
export const fieldClass = `${controlClassName} mt-2 min-h-12 w-full rounded-xl text-base`;
export const areaClass = `${controlClassName} mt-2 h-auto w-full rounded-xl py-3 text-base leading-relaxed`;
export const sendButtonClass = `${publicButtonPrimary} min-h-[52px] w-full rounded-xl text-base`;

/** What each kind of submission looks like on the choice screen and in the top bar. */
export const KINDS: Record<SubmissionKind, { label: string; hint: string; submitted: string; detail: string; tone: string; Icon: typeof StarOutlineIcon }> = {
    feedback: {
        label: 'serviceFeedback.typeFeedback',
        hint: 'serviceFeedback.typeFeedbackHint',
        submitted: 'serviceFeedback.feedbackSubmitted',
        detail: 'serviceFeedback.feedbackSubmittedDetail',
        tone: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
        Icon: StarOutlineIcon,
    },
    suggestion: {
        label: 'serviceFeedback.typeSuggestion',
        hint: 'serviceFeedback.typeSuggestionHint',
        submitted: 'serviceFeedback.suggestionSubmitted',
        detail: 'serviceFeedback.suggestionSubmittedDetail',
        tone: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        Icon: MessageSquareIcon,
    },
    complaint: {
        label: 'serviceFeedback.typeComplaint',
        hint: 'serviceFeedback.typeComplaintHint',
        submitted: 'serviceFeedback.complaintSubmitted',
        detail: 'serviceFeedback.complaintSubmittedDetail',
        tone: 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300',
        Icon: AlertTriangle,
    },
};

/**
 * The issue category — WHAT KIND of issue this is (waiting time, staff
 * conduct…), never WHERE it belongs (that is the relevant work unit). One
 * optional choice as tap-to-pick chips; tapping the chosen one again clears
 * it. The chosen chip carries a check mark, not only a colour. `embedded`
 * draws it inside another card (the organization form's first step), as a
 * fieldset of its own so it never reads as part of the unit selector.
 */
export function CategoryChips({ categories, value, onChange, error, kind = 'feedback', embedded = false }: {
    categories: CategoryOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    kind?: SubmissionKind;
    embedded?: boolean;
}) {
    const { t } = useLocale();

    if (categories.length === 0) return null;

    const chosen = categories.find((category) => category.id === value);
    const describedBy = ['issue-category-hint', chosen?.description ? 'issue-category-description' : null, error ? 'issue-category-error' : null].filter(Boolean).join(' ');

    return (
        <fieldset className={embedded ? 'min-w-0 border-t border-(--app-border) pt-5' : cardClass} data-field aria-describedby={describedBy}>
            <legend className={`${labelClass} ${embedded ? 'float-left w-full' : ''}`}>
                {t('serviceFeedback.issueCategory')}
                <span className={optionalClass}>({t('serviceFeedback.clientNameHint')})</span>
            </legend>
            <p id="issue-category-hint" className={`${embedded ? 'clear-both' : ''} mt-1 text-xs ${mutedClass}`}>{t(`serviceFeedback.issueCategoryHint_${kind}`)}</p>
            <div role="radiogroup" aria-labelledby="issue-category-hint" aria-label={t('serviceFeedback.issueCategory')} className="mt-3 flex flex-wrap gap-2">
                {categories.map((category) => {
                    const selected = value === category.id;

                    return (
                        <button
                            key={category.id}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            title={category.description ?? undefined}
                            onClick={() => onChange(selected ? '' : category.id)}
                            className={`inline-flex min-h-11 max-w-full items-center gap-1.5 rounded-full border px-4 text-sm font-medium transition-colors focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 ${selected
                                ? 'border-primary bg-primary font-semibold text-white'
                                : 'border-(--app-border-strong) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted)'}`}
                        >
                            {selected && <CheckMark />}
                            <span className="min-w-0 wrap-anywhere">{category.name}</span>
                        </button>
                    );
                })}
            </div>
            {chosen?.description && <p id="issue-category-description" className={`mt-2 text-xs ${mutedClass}`} aria-live="polite">{chosen.description}</p>}
            {error && <p id="issue-category-error" role="alert" className={errorClass}>{error}</p>}
        </fieldset>
    );
}

/** The mark inside a chosen chip, so the selection does not rely on colour alone. */
function CheckMark() {
    return (
        <svg aria-hidden="true" viewBox="0 0 20 20" className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 10.5l4 4 8-9" />
        </svg>
    );
}

export const SUBMITTED_KEY: Record<SubmissionKind, string> = {
    feedback: KINDS.feedback.submitted,
    suggestion: KINDS.suggestion.submitted,
    complaint: KINDS.complaint.submitted,
};

/** Long enough to read an Amharic sentence on a phone before the popup goes. */
export const ERROR_TOAST_MS = 9000;

/**
 * Scroll the first error into the middle of the screen and put the cursor in
 * its field. On a phone the form is longer than the screen, so a field error
 * rendered above the Send button was otherwise never seen. Each field's card
 * carries `data-field`; a form-wide message has no field to focus.
 */
export function revealFirstError(form: HTMLFormElement | null): void {
    if (!form) return;
    // Wait for React to paint the errors that came back with the response (and, on a
    // multi-step form, the step that holds them): two frames, so a step switch is painted too.
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        const alert = form.querySelector<HTMLElement>('[role="alert"]');
        if (!alert) return;
        alert.scrollIntoView({ block: 'center', behavior: 'smooth' });
        alert.closest<HTMLElement>('[data-field]')
            ?.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea, [role="radio"]')
            ?.focus({ preventScroll: true });
    }));
}

/**
 * What the visitor is told after pressing Send, on any device, as a popup:
 *
 * - sent: a success popup (with the reference for a complaint), and the page
 *   opens at the top where the receipt is, instead of keeping the long form's
 *   scroll position, which left the receipt above the screen on a phone;
 * - refused or incomplete: an error popup naming the problem, and the first
 *   field error scrolled into view;
 * - no connection, or an error page instead of an answer (a 413 for photos
 *   too large, a 429, a 5xx from the web server): an error popup instead of
 *   silence or Inertia's raw error window.
 */
export function useSubmissionOutcome(kind: SubmissionKind, formRef: RefObject<HTMLFormElement | null>) {
    const { t } = useLocale();
    const listeners = useRef<Array<() => void>>([]);
    const stopListening = () => {
        listeners.current.forEach((off) => off());
        listeners.current = [];
    };

    // Leaving the page mid-submission must not leave the listeners behind.
    useEffect(() => stopListening, []);

    /*
     * onInvalid runs before the first error is revealed: a multi-step form uses
     * it to open the step that holds the error.
     */
    return (onSent?: () => void, onInvalid?: (errors: Record<string, string>) => void): Partial<VisitOptions> => ({
        preserveScroll: 'errors',
        onStart: () => {
            stopListening();
            listeners.current = [
                router.on('networkError', () => {
                    toast.error(t('serviceFeedback.sendFailedOffline'), { duration: ERROR_TOAST_MS });
                    return false;
                }),
                router.on('httpException', (event) => {
                    const status = event.detail.response.status;
                    // Each refusal says what it is: a 419 is an expired page, not a server fault; a 403 is not "try again".
                    const key = status === 413 ? 'sendFailedTooLarge'
                        : status === 429 ? 'sendFailedTooMany'
                        : status === 419 ? 'sendFailedExpired'
                        : status === 403 ? 'sendFailedForbidden'
                        : 'sendFailedServer';
                    toast.error(t(`serviceFeedback.${key}`), { duration: ERROR_TOAST_MS });
                    return false;
                }),
            ];
        },
        onFinish: stopListening,
        onSuccess: (page: Page) => {
            const props = page.props as { submitted?: boolean; receipt?: Receipt | null };
            // A redirect without a receipt (an expired form, a closed link) is not a success;
            // its own notice arrives as a flash popup from the layout.
            if (!props.submitted) return;
            const reference = props.receipt?.reference;
            toast.success(t(SUBMITTED_KEY[kind]), {
                description: reference ? `${t('serviceFeedback.referenceNumber')}: ${reference}` : undefined,
                duration: reference ? ERROR_TOAST_MS : 6000,
            });
            onSent?.();
        },
        onError: (errors: Record<string, string>) => {
            const messages = Object.values(errors).filter(Boolean);
            if (messages.length === 0) return;
            onInvalid?.(errors);
            // One problem: say it. Several: point at the highlighted fields and name the first.
            if (messages.length === 1) toast.error(messages[0], { duration: ERROR_TOAST_MS });
            else toast.error(t('serviceFeedback.checkHighlightedFields'), { description: messages[0], duration: ERROR_TOAST_MS });
            revealFirstError(formRef.current);
        },
    });
}

/** The privacy promise must match what the form asks for (PublicSubmissionPolicy). */
export function privacyNoteKey(requirements: SubmissionRequirements): string {
    return requirements.anonymous_allowed ? 'serviceFeedback.publicPrivacyNoteTipOff' : 'serviceFeedback.publicPrivacyNoteIdentified';
}

/** "(Required)" or "(Optional)" beside a label; the input carries the real `required`. */
export function RequirementMark({ required }: { required: boolean }) {
    const { t } = useLocale();

    return <span className={optionalClass}>({t(required ? 'serviceFeedback.requiredMark' : 'serviceFeedback.clientNameHint')})</span>;
}

export interface IdentityData {
    client_name: string;
    client_contact: string;
    client_email: string;
    submitter_organization?: string;
}

export type IdentityErrors = Partial<Record<'client_name' | 'client_contact' | 'client_email' | 'submitter_organization', string>>;

/**
 * Who is sending: full name and phone (required on Feedback and Complaint,
 * optional on a Tip-off), e-mail, and — on Feedback and Complaint — the
 * submitter's own organization. What is required comes from the server's
 * policy; the page only reflects it.
 */
export function IdentityFields({ requirements, data, set, errors }: {
    requirements: SubmissionRequirements;
    data: IdentityData;
    set: (field: keyof IdentityData, value: string) => void;
    errors: IdentityErrors;
}) {
    const { t } = useLocale();

    return (
        <>
            <div data-field>
                <label htmlFor="client_name" className={labelClass}>
                    {t('serviceFeedback.clientName')}
                    <RequirementMark required={requirements.name_required} />
                </label>
                <input id="client_name" type="text" autoComplete="name" maxLength={120} required={requirements.name_required} aria-required={requirements.name_required} value={data.client_name} onChange={(e) => set('client_name', e.target.value)} className={fieldClass} />
                {errors.client_name && <p role="alert" className={errorClass}>{errors.client_name}</p>}
            </div>
            <div data-field>
                <label htmlFor="client_contact" className={labelClass}>
                    {t('serviceFeedback.clientContact')}
                    <RequirementMark required={requirements.phone_required} />
                </label>
                <input id="client_contact" type="tel" inputMode="tel" autoComplete="tel" maxLength={40} required={requirements.phone_required} aria-required={requirements.phone_required} value={data.client_contact} onChange={(e) => set('client_contact', e.target.value)} className={fieldClass} />
                {errors.client_contact && <p role="alert" className={errorClass}>{errors.client_contact}</p>}
            </div>
            <div data-field>
                <label htmlFor="client_email" className={labelClass}>
                    {t('serviceFeedback.clientEmail')}
                    <RequirementMark required={false} />
                </label>
                <input id="client_email" type="email" inputMode="email" autoComplete="email" maxLength={190} value={data.client_email} onChange={(e) => set('client_email', e.target.value)} className={fieldClass} />
                {errors.client_email && <p role="alert" className={errorClass}>{errors.client_email}</p>}
            </div>
            {requirements.submitter_organization && data.submitter_organization !== undefined && (
                <div data-field>
                    <label htmlFor="submitter_organization" className={labelClass}>
                        {t('serviceFeedback.submitterOrganization')}
                        <RequirementMark required={false} />
                    </label>
                    <input id="submitter_organization" type="text" autoComplete="organization" maxLength={200} value={data.submitter_organization} onChange={(e) => set('submitter_organization', e.target.value)} className={fieldClass} />
                    {errors.submitter_organization && <p role="alert" className={errorClass}>{errors.submitter_organization}</p>}
                </div>
            )}
        </>
    );
}

/** The named-submitter section of Feedback and Complaint. */
export function AboutYou(props: Parameters<typeof IdentityFields>[0]) {
    const { t } = useLocale();

    return (
        <div role="group" aria-labelledby="about-you-title" className={`${cardClass} space-y-4`}>
            <p id="about-you-title" className="text-sm font-semibold text-(--app-foreground)">{t('serviceFeedback.sectionAboutYou')}</p>
            <IdentityFields {...props} />
        </div>
    );
}

/** A refusal that belongs to no field (a replayed form, storage trouble), shown at the top of the form. */
export function FormNotice({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <p role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
            {message}
        </p>
    );
}

/** Optional evidence, shared by the Tip-off and the Complaint forms. */
export function EvidenceSection({ files, onChange, limits, error }: { files: File[]; onChange: (files: File[]) => void; limits: EvidenceLimits; error?: string }) {
    const { t } = useLocale();

    return (
        <div role="group" aria-labelledby="evidence-title" className={cardClass} data-field>
            <p id="evidence-title" className={labelClass}>
                {t('serviceFeedback.evidenceTitle')}
                <RequirementMark required={false} />
            </p>
            <p id="evidence-hint" className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>
                {t('serviceFeedback.evidenceHint')}{' '}
                {t('serviceFeedback.attachmentHint').replace('{files}', String(limits.max_files)).replace('{total}', String(limits.max_total_mb))}
            </p>
            <div className="mt-3">
                <EvidencePicker id="evidence" files={files} onChange={onChange} limits={limits} error={error} describedBy="evidence-hint" />
            </div>
        </div>
    );
}

/** The first evidence error, whichever file it was about. */
export function evidenceError(errors: Record<string, string | undefined>): string | undefined {
    return errors.attachments ?? Object.entries(errors).find(([key]) => key.startsWith('attachments.'))?.[1];
}

export const TIP_TYPE_COPY: Record<TipType, { label: string; hint: string }> = {
    CORRUPTION: { label: 'serviceFeedback.tipTypeCorruption', hint: 'serviceFeedback.tipTypeCorruptionHint' },
    IMPROPER_PRACTICE: { label: 'serviceFeedback.tipTypeImproperPractice', hint: 'serviceFeedback.tipTypeImproperPracticeHint' },
};

/**
 * The two Tip-off types as large radio cards: native radios, so keyboard and
 * screen readers get real radio-group and required semantics.
 */
export function TipTypeChooser({ tipTypes, value, onChange, error }: { tipTypes: TipType[]; value: TipType | ''; onChange: (value: TipType) => void; error?: string }) {
    const { t } = useLocale();

    return (
        <fieldset className={cardClass} data-field aria-describedby="tip-type-hint">
            <legend className="sr-only">{t('serviceFeedback.tipTypeLabel')}</legend>
            <p className={labelClass} aria-hidden="true">
                {t('serviceFeedback.tipTypeLabel')}
                <RequirementMark required />
            </p>
            <p id="tip-type-hint" className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>{t('serviceFeedback.tipTypeHint')}</p>
            <div className="mt-3 space-y-3">
                {tipTypes.map((tipType) => {
                    const chosen = value === tipType;

                    return (
                        <label
                            key={tipType}
                            className={`flex min-h-[64px] cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition-colors has-focus-visible:outline-solid has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary ${chosen
                                ? 'border-primary bg-(--app-surface-muted)'
                                : 'border-(--app-border-strong) bg-(--app-surface) hover:bg-(--app-surface-muted)'}`}
                        >
                            <input
                                type="radio"
                                name="tip_type"
                                value={tipType}
                                checked={chosen}
                                required
                                onChange={() => onChange(tipType)}
                                className="mt-1 h-5 w-5 shrink-0 accent-primary"
                            />
                            <span className="min-w-0">
                                <span className="block text-base font-semibold text-(--app-foreground)">{t(TIP_TYPE_COPY[tipType].label)}</span>
                                <span className={`mt-0.5 block text-sm leading-snug ${mutedClass}`}>{t(TIP_TYPE_COPY[tipType].hint)}</span>
                            </span>
                        </label>
                    );
                })}
            </div>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </fieldset>
    );
}
