import { FormEvent, ReactNode, RefObject, useEffect, useRef, useState } from 'react';
import { controlClassName } from '@euisis/ui';
import type { Page, VisitOptions } from '@inertiajs/core';
import { publicButtonPrimary, publicButtonSecondary } from '@/Components/public/PublicPage';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EvidencePicker from '@/Components/public/EvidencePicker';
import PublicQrActionDock, { type PublicQrCall } from '@/Components/public/PublicQrActionDock';
import { AlertTriangle, Building2, ChevronRight, MessageSquareIcon, StarIcon as StarOutlineIcon, UserIcon } from '@/Components/NavIcons';
import { Link, router, useForm } from '@inertiajs/react';
import FeedbackAppLayout, { FeedbackActionBar } from '@/Layouts/FeedbackAppLayout';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import type { EvidenceLimits, SubmissionKind, SubmissionRequirements, TipType } from '@/types/publicSubmissions';

/**
 * Public Feedback, Tip-off (ጥቆማ — internal type `suggestion`) and Complaint.
 *
 * Reached by scanning an employee's feedback QR or an institution's feedback
 * QR with any phone camera, so the page is built as the screens of a phone app
 * (FeedbackAppLayout): choose what to send, fill one short form with the Send
 * button under the thumb, get a receipt. It needs no login, no app and no
 * camera permission.
 *
 * The page never decides WHO a submission is about. The server resolved the
 * scanned token to a target and sends only what a visitor may see of it: the
 * photo and full name of an active employee, so the visitor can
 * confirm they reached the person who served them; name and logo for an
 * institution. Every URL comes from the server too (`links`, the signed photo
 * link), so the page assembles none and cannot point a form at another target.
 */

interface ServiceTypeOption {
    id: string;
    /** Position-scoped Service ID, shown to the client alongside the name. */
    service_no: string;
    name: string;
}

/** Everything the public page is permitted to know about the target. */
interface TargetDisplay {
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

interface Links {
    landing: string;
    feedback: string;
    suggestion: string;
    complaint: string;
    submitFeedback: string;
    submitSuggestion: string;
    submitComplaint: string;
}

interface Receipt {
    type: SubmissionKind;
    target_type: 'employee' | 'organization';
    target_name: string;
    reference: string | null;
    tip_type: TipType | null;
    submitted_at: string | null;
}

interface CategoryOption {
    id: string;
    name: string;
}

interface Props {
    available: boolean;
    token: string;
    target: TargetDisplay | null;
    links: Links;
    serviceTypes: ServiceTypeOption[];
    /** Active categories that apply to this type and target; empty when none are defined. */
    categories: CategoryOption[];
    submitted: boolean;
    type: 'feedback' | 'suggestion' | 'complaint' | null;
    reference: string | null;
    receipt: Receipt | null;
    /** One-time token for the form on this page; consumed by the submission. */
    formToken: string | null;
    /** Submission types the policy allows right now (brief §93). */
    enabledTypes: SubmissionKind[];
    /** What each form asks of the submitter — the server's PublicSubmissionPolicy, mirrored. */
    requirements: Record<SubmissionKind, SubmissionRequirements>;
    /** What evidence a Tip-off or Complaint may carry (PublicEvidencePolicy). */
    evidence: EvidenceLimits;
    tipTypes: TipType[];
    /** The institution's validated public number for the Call action; null when there is none. */
    call: PublicQrCall | null;
}


// ── Look ────────────────────────────────────────────────────────────────────
// Grouped cards on a soft background, 16px type in every control (no zoom on
// focus in iOS Safari), 48px+ touch targets.

const errorClass = 'mt-2 text-sm text-red-600 dark:text-red-400';
const cardClass = 'rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4';
const labelClass = 'block text-sm font-semibold text-[color:var(--app-foreground)]';
const optionalClass = 'ml-1 font-normal text-[color:var(--app-muted-foreground)]';
const mutedClass = 'text-[color:var(--app-muted-foreground)]';
// min-h, not h: the shared control sets its own height and would win over a plain h-12.
const fieldClass = `${controlClassName} mt-2 min-h-12 w-full rounded-xl text-base`;
const areaClass = `${controlClassName} mt-2 h-auto w-full rounded-xl py-3 text-base leading-relaxed`;
const sendButtonClass = `${publicButtonPrimary} min-h-[52px] w-full rounded-xl text-base`;

/** What each kind of submission looks like on the choice screen and in the top bar. */
const KINDS: Record<SubmissionKind, { label: string; hint: string; submitted: string; detail: string; tone: string; Icon: typeof StarOutlineIcon }> = {
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

/** Photo frames: a portrait on the choice screen, a small round avatar beside the name on a form. */
const PHOTO_FRAMES = {
    lg: { box: 'h-40 w-32 rounded-3xl', width: 128, height: 160, icon: 'h-16 w-16' },
    md: { box: 'h-11 w-11 rounded-full', width: 44, height: 44, icon: 'h-6 w-6' },
} as const;

/**
 * The scanned employee's photo, so the visitor can see they reached the right
 * person. The frame has a fixed size, so nothing shifts while it loads under a
 * soft placeholder. No photo, or one that fails to load, shows a neutral
 * silhouette — never a broken image — and the name and the choices stay.
 * Callers key it by URL, so another employee's page never shows this photo.
 */
function EmployeePhoto({ url, size }: { url: string | null | undefined; size: keyof typeof PHOTO_FRAMES }) {
    const { t } = useLocale();
    const frame = PHOTO_FRAMES[size];
    const imageRef = useRef<HTMLImageElement>(null);
    const [state, setState] = useState<'loading' | 'loaded' | 'failed'>(url ? 'loading' : 'failed');

    // A photo the browser already holds can finish loading before React listens for it.
    useEffect(() => {
        const image = imageRef.current;
        if (image?.complete) setState(image.naturalWidth > 0 ? 'loaded' : 'failed');
    }, []);

    return (
        <span className={`relative block shrink-0 overflow-hidden bg-[color:var(--app-surface-muted)] ring-1 ring-[color:var(--app-border)] ${frame.box}`}>
            {url && state !== 'failed' ? (
                <>
                    <img
                        ref={imageRef}
                        src={url}
                        // The name is printed right beside the small avatar; only the large photo needs a description.
                        alt={size === 'lg' ? t('serviceFeedback.employeePhotoAlt') : ''}
                        width={frame.width}
                        height={frame.height}
                        decoding="async"
                        referrerPolicy="no-referrer"
                        onLoad={() => setState('loaded')}
                        onError={() => setState('failed')}
                        className={`h-full w-full object-cover object-[50%_25%] transition-opacity duration-200 ${state === 'loaded' ? 'opacity-100' : 'opacity-0'}`}
                    />
                    {state === 'loading' && <span className="absolute inset-0 animate-pulse bg-[color:var(--app-border)] opacity-60" aria-hidden="true" />}
                </>
            ) : (
                <span className="flex h-full w-full items-center justify-center text-[color:var(--app-muted-foreground)]" aria-hidden="true">
                    <UserIcon className={frame.icon} />
                </span>
            )}
        </span>
    );
}

/** The employee's photo, or the institution's logo or a building mark. */
function TargetMark({ target, size }: { target: TargetDisplay; size: 'md' | 'lg' }) {
    if (target.target_type === 'employee') {
        return <EmployeePhoto key={target.photo_url ?? 'none'} url={target.photo_url} size="md" />;
    }
    const box = size === 'lg' ? 'h-14 w-14 rounded-2xl' : 'h-11 w-11 rounded-xl';

    if (target.logo_url) {
        return <img src={target.logo_url} alt="" className={`${box} shrink-0 border border-[color:var(--app-border)] bg-white object-contain p-1`} />;
    }

    return (
        <span className={`${box} flex shrink-0 items-center justify-center bg-[color:var(--app-surface-muted)] text-[color:var(--color-primary)]`} aria-hidden="true">
            <Building2 className={size === 'lg' ? 'h-7 w-7' : 'h-6 w-6'} />
        </span>
    );
}

/**
 * The scanned employee as the choice screen confirms them: photo, full name
 * and — directly under the name — the job designation they hold now. Nothing
 * else. An employee with no current post shows no designation line at all.
 */
function EmployeeIdentityCard({ target }: { target: TargetDisplay }) {
    return (
        <div className={`${cardClass} flex flex-col items-center px-5 pb-5 pt-6 text-center`}>
            <EmployeePhoto key={target.photo_url ?? 'none'} url={target.photo_url} size="lg" />
            <h2 className="mt-4 text-xl font-bold leading-snug text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{target.display_name}</h2>
            {target.position_title && (
                <p className={`mt-1 text-base leading-snug ${mutedClass} [overflow-wrap:anywhere]`}>{target.position_title}</p>
            )}
        </div>
    );
}

/** Who the visitor is writing about, as they may see it. */
function TargetCard({ target, intro }: { target: TargetDisplay; intro?: string }) {
    // Under the name: an institution's short name, or the employee's job designation.
    const detail = target.target_type === 'organization' ? target.short_name ?? '' : target.position_title ?? '';

    return (
        <div className={cardClass}>
            <div className="flex items-center gap-3">
                <TargetMark target={target} size={intro ? 'lg' : 'md'} />
                <div className="min-w-0">
                    <p className="font-semibold leading-snug text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{target.display_name}</p>
                    {detail !== '' && <p className={`mt-0.5 text-sm leading-snug ${mutedClass}`}>{detail}</p>}
                </div>
            </div>
            {intro && <p className={`mt-3 border-t border-[color:var(--app-border)] pt-3 text-sm ${mutedClass}`}>{intro}</p>}
        </div>
    );
}

function ServiceSelect({ serviceTypes, value, onChange, error, optional }: {
    serviceTypes: ServiceTypeOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    optional: boolean;
}) {
    const { t } = useLocale();

    return (
        <div className={cardClass} data-field>
            <label htmlFor="position_service_id" className={labelClass}>
                {t('serviceFeedback.serviceTypeOrId')}
                {optional && <span className={optionalClass}>({t('serviceFeedback.clientNameHint')})</span>}
            </label>
            {serviceTypes.length === 0 && (
                <p className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {t('serviceFeedback.noServiceTypesConfigured')}
                </p>
            )}
            <select id="position_service_id" value={value} onChange={(e) => onChange(e.target.value)} className={fieldClass}>
                <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                {serviceTypes.map((service) => (
                    <option key={service.id} value={service.id}>{service.service_no ? `${service.service_no} — ${service.name}` : service.name}</option>
                ))}
            </select>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

/** Categories as tap-to-pick chips; tapping the chosen one again clears it (the choice is optional). */
function CategoryChips({ categories, value, onChange, error }: { categories: CategoryOption[]; value: string; onChange: (value: string) => void; error?: string }) {
    const { t } = useLocale();

    if (categories.length === 0) return null;

    return (
        <div className={cardClass} data-field>
            <span id="category-label" className={labelClass}>
                {t('serviceFeedback.category')}
                <span className={optionalClass}>({t('serviceFeedback.clientNameHint')})</span>
            </span>
            <div role="radiogroup" aria-labelledby="category-label" className="mt-3 flex flex-wrap gap-2">
                {categories.map((category) => {
                    const chosen = value === category.id;

                    return (
                        <button
                            key={category.id}
                            type="button"
                            role="radio"
                            aria-checked={chosen}
                            onClick={() => onChange(chosen ? '' : category.id)}
                            className={`min-h-11 rounded-full border px-4 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-primary)] ${chosen
                                ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary)] text-white'
                                : 'border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}
                        >
                            {category.name}
                        </button>
                    );
                })}
            </div>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

const SUBMITTED_KEY: Record<SubmissionKind, string> = {
    feedback: KINDS.feedback.submitted,
    suggestion: KINDS.suggestion.submitted,
    complaint: KINDS.complaint.submitted,
};

/** Long enough to read an Amharic sentence on a phone before the popup goes. */
const ERROR_TOAST_MS = 9000;

/**
 * Scroll the first error into the middle of the screen and put the cursor in
 * its field. On a phone the form is longer than the screen, so a field error
 * rendered above the Send button was otherwise never seen. Each field's card
 * carries `data-field`; a form-wide message has no field to focus.
 */
function revealFirstError(form: HTMLFormElement | null): void {
    if (!form) return;
    // Wait for React to paint the errors that came back with the response.
    window.requestAnimationFrame(() => {
        const alert = form.querySelector<HTMLElement>('[role="alert"]');
        if (!alert) return;
        alert.scrollIntoView({ block: 'center', behavior: 'smooth' });
        alert.closest<HTMLElement>('[data-field]')
            ?.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea, [role="radio"]')
            ?.focus({ preventScroll: true });
    });
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
function useSubmissionOutcome(kind: SubmissionKind, formRef: RefObject<HTMLFormElement>) {
    const { t } = useLocale();
    const listeners = useRef<Array<() => void>>([]);
    const stopListening = () => {
        listeners.current.forEach((off) => off());
        listeners.current = [];
    };

    // Leaving the page mid-submission must not leave the listeners behind.
    useEffect(() => stopListening, []);

    return (onSent?: () => void): Partial<VisitOptions> => ({
        preserveScroll: 'errors',
        onStart: () => {
            stopListening();
            listeners.current = [
                router.on('exception', () => {
                    toast.error(t('serviceFeedback.sendFailedOffline'), { duration: ERROR_TOAST_MS });
                    return false;
                }),
                router.on('invalid', (event) => {
                    const status = event.detail.response.status;
                    const key = status === 413 ? 'sendFailedTooLarge' : status === 429 ? 'sendFailedTooMany' : 'sendFailedServer';
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
            // One problem: say it. Several: point at the highlighted fields and name the first.
            if (messages.length === 1) toast.error(messages[0], { duration: ERROR_TOAST_MS });
            else toast.error(t('serviceFeedback.checkHighlightedFields'), { description: messages[0], duration: ERROR_TOAST_MS });
            revealFirstError(formRef.current);
        },
    });
}

/** The privacy promise must match what the form asks for (PublicSubmissionPolicy). */
function privacyNoteKey(requirements: SubmissionRequirements): string {
    return requirements.anonymous_allowed ? 'serviceFeedback.publicPrivacyNoteTipOff' : 'serviceFeedback.publicPrivacyNoteIdentified';
}

/** "(Required)" or "(Optional)" beside a label; the input carries the real `required`. */
function RequirementMark({ required }: { required: boolean }) {
    const { t } = useLocale();

    return <span className={optionalClass}>({t(required ? 'serviceFeedback.requiredMark' : 'serviceFeedback.clientNameHint')})</span>;
}

interface IdentityData {
    client_name: string;
    client_contact: string;
    client_email: string;
    submitter_organization?: string;
}

type IdentityErrors = Partial<Record<'client_name' | 'client_contact' | 'client_email' | 'submitter_organization', string>>;

/**
 * Who is sending: full name and phone (required on Feedback and Complaint,
 * optional on a Tip-off), e-mail, and — on Feedback and Complaint — the
 * submitter's own organization. What is required comes from the server's
 * policy; the page only reflects it.
 */
function IdentityFields({ requirements, data, set, errors }: {
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
function AboutYou(props: Parameters<typeof IdentityFields>[0]) {
    const { t } = useLocale();

    return (
        <div role="group" aria-labelledby="about-you-title" className={`${cardClass} space-y-4`}>
            <p id="about-you-title" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.sectionAboutYou')}</p>
            <IdentityFields {...props} />
        </div>
    );
}

/** A refusal that belongs to no field (a replayed form, storage trouble), shown at the top of the form. */
function FormNotice({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <p role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
            {message}
        </p>
    );
}

/** One form screen: the fields scroll, the Send button stays at the bottom edge, with upload progress above it. */
function FormScreen({ formRef, onSubmit, requirements, processing, progress, submitLabel, children }: {
    formRef: RefObject<HTMLFormElement>;
    onSubmit: (event: FormEvent) => void;
    requirements: SubmissionRequirements;
    processing: boolean;
    /** Upload progress of a form carrying files; null while nothing is uploading. */
    progress?: number | null;
    submitLabel: string;
    children: ReactNode;
}) {
    const { t } = useLocale();
    const uploading = processing && progress !== null && progress !== undefined;

    return (
        <form ref={formRef} onSubmit={onSubmit} noValidate className="flex flex-1 flex-col">
            <div className="space-y-4 px-4 pb-6 pt-4">
                {children}
                <p className={`px-1 text-center text-xs leading-relaxed ${mutedClass}`}>{t(privacyNoteKey(requirements))}</p>
            </div>
            <FeedbackActionBar>
                {uploading && (
                    <div className="mb-2" role="status" aria-live="polite">
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]">
                            <div className="h-full rounded-full bg-[color:var(--color-primary)] transition-[width]" style={{ width: `${progress}%` }} />
                        </div>
                        <p className={`mt-1 text-center text-xs ${mutedClass}`}>{t('serviceFeedback.uploadProgress').replace('{percent}', String(progress))}</p>
                    </div>
                )}
                <button type="submit" disabled={processing} className={sendButtonClass}>
                    {processing ? t('serviceFeedback.submitting') : submitLabel}
                </button>
            </FeedbackActionBar>
        </form>
    );
}

/** Optional evidence, shared by the Tip-off and the Complaint forms. */
function EvidenceSection({ files, onChange, limits, error }: { files: File[]; onChange: (files: File[]) => void; limits: EvidenceLimits; error?: string }) {
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
function evidenceError(errors: Record<string, string | undefined>): string | undefined {
    return errors.attachments ?? Object.entries(errors).find(([key]) => key.startsWith('attachments.'))?.[1];
}

interface FormProps {
    target: TargetDisplay;
    serviceTypes: ServiceTypeOption[];
    categories: CategoryOption[];
    links: Links;
    formToken: string | null;
    requirements: SubmissionRequirements;
    evidence: EvidenceLimits;
}

const TIP_TYPE_COPY: Record<TipType, { label: string; hint: string }> = {
    CORRUPTION: { label: 'serviceFeedback.tipTypeCorruption', hint: 'serviceFeedback.tipTypeCorruptionHint' },
    IMPROPER_PRACTICE: { label: 'serviceFeedback.tipTypeImproperPractice', hint: 'serviceFeedback.tipTypeImproperPracticeHint' },
};

/**
 * The two Tip-off types as large radio cards: native radios, so keyboard and
 * screen readers get real radio-group and required semantics.
 */
function TipTypeChooser({ tipTypes, value, onChange, error }: { tipTypes: TipType[]; value: TipType | ''; onChange: (value: TipType) => void; error?: string }) {
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
                            className={`flex min-h-[64px] cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition-colors has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-[color:var(--color-primary)] ${chosen
                                ? 'border-[color:var(--color-primary)] bg-[color:var(--app-surface-muted)]'
                                : 'border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] hover:bg-[color:var(--app-surface-muted)]'}`}
                        >
                            <input
                                type="radio"
                                name="tip_type"
                                value={tipType}
                                checked={chosen}
                                required
                                onChange={() => onChange(tipType)}
                                className="mt-1 h-5 w-5 shrink-0 accent-[color:var(--color-primary)]"
                            />
                            <span className="min-w-0">
                                <span className="block text-base font-semibold text-[color:var(--app-foreground)]">{t(TIP_TYPE_COPY[tipType].label)}</span>
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

/**
 * The digital Tip-off (ጥቆማ) form, from the paper intake form: the target is
 * already known from the scanned QR; the tipster chooses the type and states
 * the facts; when, where, who else and a related service are optional and
 * folded away; evidence and the tipster's own details are optional. Nothing
 * the system knows — channel, date received, reference — is asked.
 */
function TipOffForm({ target, serviceTypes, categories, links, formToken, requirements, evidence, tipTypes }: FormProps & { tipTypes: TipType[] }) {
    const { t } = useLocale();
    const formRef = useRef<HTMLFormElement>(null);
    const outcome = useSubmissionOutcome('suggestion', formRef);
    const { data, setData, post, processing, progress, errors, clearErrors } = useForm({
        form_token: formToken ?? '',
        tip_type: '' as TipType | '',
        message: '',
        incident_date: '',
        incident_location: '',
        involved_parties: '',
        position_service_id: '',
        category_id: '',
        client_name: '',
        client_contact: '',
        client_email: '',
        submitter_address: { city: '', sub_city: '', woreda: '', detail: '' },
        attachments: [] as File[],
    });
    // Each field's error is cleared the moment the visitor changes it, so a fixed field never stays red.
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(links.submitSuggestion, { forceFormData: true, ...outcome() });
    };

    return (
        <FormScreen formRef={formRef} onSubmit={submit} requirements={requirements} processing={processing} progress={progress?.percentage ?? null} submitLabel={t('serviceFeedback.submitSuggestion')}>
            <FormNotice message={errors.form_token} />
            <TargetCard target={target} />

            <TipTypeChooser tipTypes={tipTypes} value={data.tip_type} onChange={(value) => { setData('tip_type', value); clearErrors('tip_type'); }} error={errors.tip_type} />

            <div role="group" aria-labelledby="tip-details-title" className={`${cardClass} space-y-4`}>
                <p id="tip-details-title" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.tipDetailsTitle')}</p>
                <div data-field>
                    <label htmlFor="entry-message" className={labelClass}>
                        {t('serviceFeedback.suggestionText')}
                        <RequirementMark required />
                    </label>
                    <p id="tip-details-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.tipDetailsHint')}</p>
                    <textarea id="entry-message" required aria-required="true" aria-describedby="tip-details-hint" maxLength={5000} rows={7} value={data.message} onChange={(event) => { setData('message', event.target.value); clearErrors('message'); }} className={areaClass} />
                    {errors.message && <p role="alert" className={errorClass}>{errors.message}</p>}
                </div>

                <details className="group rounded-xl border border-[color:var(--app-border)] p-3" open={Boolean(errors.incident_date || errors.incident_location || errors.involved_parties || errors.position_service_id || errors.category_id)}>
                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold text-[color:var(--app-foreground)]">
                        {t('serviceFeedback.tipMoreDetails')}
                        <ChevronRight className="h-5 w-5 transition-transform group-open:rotate-90" aria-hidden="true" />
                    </summary>
                    <div className="mt-3 space-y-4">
                        <div data-field>
                            <label htmlFor="incident-date" className={labelClass}>{t('serviceFeedback.tipIncidentDate')}</label>
                            <div className="mt-2"><LocalizedDatePicker id="incident-date" value={data.incident_date} onChange={(iso) => setData('incident_date', iso)} className="min-h-12 rounded-xl text-base" /></div>
                            {errors.incident_date && <p role="alert" className={errorClass}>{errors.incident_date}</p>}
                        </div>
                        <div data-field>
                            <label htmlFor="incident-location" className={labelClass}>{t('serviceFeedback.tipIncidentLocation')}</label>
                            <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => setData('incident_location', event.target.value)} className={fieldClass} />
                            {errors.incident_location && <p role="alert" className={errorClass}>{errors.incident_location}</p>}
                        </div>
                        <div data-field>
                            <label htmlFor="involved-parties" className={labelClass}>{t('serviceFeedback.tipInvolvedParties')}</label>
                            <p id="involved-parties-hint" className={`mt-1 text-xs ${mutedClass}`}>{t('serviceFeedback.tipInvolvedPartiesHint')}</p>
                            <textarea id="involved-parties" aria-describedby="involved-parties-hint" maxLength={2000} rows={3} value={data.involved_parties} onChange={(event) => setData('involved_parties', event.target.value)} className={areaClass} />
                            {errors.involved_parties && <p role="alert" className={errorClass}>{errors.involved_parties}</p>}
                        </div>
                        {target.target_type === 'employee' && serviceTypes.length > 0 && (
                            <div data-field>
                                <label htmlFor="position_service_id" className={labelClass}>{t('serviceFeedback.tipRelatedService')}</label>
                                <select id="position_service_id" value={data.position_service_id} onChange={(e) => setData('position_service_id', e.target.value)} className={fieldClass}>
                                    <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                                    {serviceTypes.map((service) => (
                                        <option key={service.id} value={service.id}>{service.service_no ? `${service.service_no} — ${service.name}` : service.name}</option>
                                    ))}
                                </select>
                                {errors.position_service_id && <p role="alert" className={errorClass}>{errors.position_service_id}</p>}
                            </div>
                        )}
                    </div>
                </details>
            </div>

            <CategoryChips categories={categories} value={data.category_id} onChange={(value) => setData('category_id', value)} error={errors.category_id} />

            {requirements.evidence_allowed && (
                <EvidenceSection files={data.attachments} onChange={(files) => { setData('attachments', files); clearErrors(); }} limits={evidence} error={evidenceError(fieldErrors)} />
            )}

            <div role="group" aria-labelledby="tipster-title" className={`${cardClass} space-y-4`}>
                <div>
                    <p id="tipster-title" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.tipsterTitle')}</p>
                    <p className={`mt-1 text-xs leading-relaxed ${mutedClass}`}>{t('serviceFeedback.tipsterHint')}</p>
                </div>
                <IdentityFields requirements={requirements} data={data} set={(field, value) => { setData(field as 'client_name', value); clearErrors(field as 'client_name'); }} errors={errors} />
                <details className="group rounded-xl border border-[color:var(--app-border)] p-3">
                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold text-[color:var(--app-foreground)]">
                        {t('serviceFeedback.tipsterAddressToggle')}
                        <ChevronRight className="h-5 w-5 transition-transform group-open:rotate-90" aria-hidden="true" />
                    </summary>
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        {(['city', 'sub_city', 'woreda', 'detail'] as const).map((part) => (
                            <div key={part} data-field className={part === 'detail' ? 'sm:col-span-2' : ''}>
                                <label htmlFor={`address-${part}`} className={labelClass}>{t(`serviceFeedback.${{ city: 'addressCity', sub_city: 'addressSubCity', woreda: 'addressWoreda', detail: 'addressDetail' }[part]}`)}</label>
                                <input id={`address-${part}`} type="text" autoComplete={part === 'city' ? 'address-level2' : 'off'} maxLength={part === 'detail' ? 255 : 100} value={data.submitter_address[part]} onChange={(e) => setData('submitter_address', { ...data.submitter_address, [part]: e.target.value })} className={fieldClass} />
                                {fieldErrors[`submitter_address.${part}`] && <p role="alert" className={errorClass}>{fieldErrors[`submitter_address.${part}`]}</p>}
                            </div>
                        ))}
                    </div>
                </details>
            </div>
        </FormScreen>
    );
}

/** The Complaint form: a named complainant, the details, optional when/where and evidence. */
function ComplaintForm({ target, serviceTypes, categories, links, formToken, requirements, evidence }: FormProps) {
    const { t } = useLocale();
    const formRef = useRef<HTMLFormElement>(null);
    const outcome = useSubmissionOutcome('complaint', formRef);
    const { data, setData, post, processing, progress, errors, clearErrors } = useForm({
        form_token: formToken ?? '',
        position_service_id: '', category_id: '', message: '',
        client_name: '', client_contact: '', client_email: '', submitter_organization: '',
        incident_date: '', incident_location: '', attachments: [] as File[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(links.submitComplaint, { forceFormData: true, ...outcome() });
    };

    return (
        <FormScreen formRef={formRef} onSubmit={submit} requirements={requirements} processing={processing} progress={progress?.percentage ?? null} submitLabel={t('serviceFeedback.submitComplaint')}>
            <FormNotice message={errors.form_token} />
            <TargetCard target={target} />

            {/* The message is the point of this screen, so it comes first and is the biggest field. */}
            <div className={cardClass} data-field>
                <label htmlFor="entry-message" className={labelClass}>
                    {t('serviceFeedback.complaintDescription')}
                    <RequirementMark required />
                </label>
                <textarea id="entry-message" required aria-required="true" maxLength={5000} rows={7} value={data.message} onChange={(event) => { setData('message', event.target.value); clearErrors('message'); }} className={areaClass} />
                {errors.message && <p role="alert" className={errorClass}>{errors.message}</p>}
            </div>

            <CategoryChips categories={categories} value={data.category_id} onChange={(value) => setData('category_id', value)} error={errors.category_id} />

            {target.target_type === 'employee' && (
                <ServiceSelect serviceTypes={serviceTypes} value={data.position_service_id} onChange={(value) => setData('position_service_id', value)} error={errors.position_service_id} optional />
            )}

            <div role="group" aria-labelledby="when-where-title" className={`${cardClass} space-y-4`}>
                <p id="when-where-title" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.sectionWhenWhere')}</p>
                <div data-field>
                    <label htmlFor="incident-date" className={labelClass}>{t('serviceFeedback.incidentDate')}</label>
                    <div className="mt-2"><LocalizedDatePicker id="incident-date" value={data.incident_date} onChange={(iso) => setData('incident_date', iso)} className="min-h-12 rounded-xl text-base" /></div>
                    {errors.incident_date && <p role="alert" className={errorClass}>{errors.incident_date}</p>}
                </div>
                <div data-field>
                    <label htmlFor="incident-location" className={labelClass}>{t('serviceFeedback.incidentLocation')}</label>
                    <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => setData('incident_location', event.target.value)} className={fieldClass} />
                    {errors.incident_location && <p role="alert" className={errorClass}>{errors.incident_location}</p>}
                </div>
            </div>

            {requirements.evidence_allowed && (
                <EvidenceSection files={data.attachments} onChange={(files) => setData('attachments', files)} limits={evidence} error={evidenceError(errors as Record<string, string | undefined>)} />
            )}

            <AboutYou requirements={requirements} data={data} set={(field, value) => { setData(field as 'client_name', value); clearErrors(field); }} errors={errors} />
        </FormScreen>
    );
}

const RATING_VALUES = [1, 2, 3, 4, 5] as const;

function StarIcon({ filled }: { filled: boolean }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className={`h-11 w-11 transition-colors ${filled ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600'}`} fill="currentColor">
            <path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
        </svg>
    );
}

function RatingForm({ target, serviceTypes, categories, links, formToken, requirements }: Omit<FormProps, 'evidence'>) {
    const { t } = useLocale();
    /*
     * Hover preview is tracked separately from the committed rating so the
     * stars light up under a mouse without changing the value until a click.
     * Touch devices never set this, so they see only the committed state.
     */
    const [hoveredRating, setHoveredRating] = useState<number | null>(null);
    const formRef = useRef<HTMLFormElement>(null);
    const outcome = useSubmissionOutcome('feedback', formRef);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        form_token: formToken ?? '',
        position_service_id: '',
        category_id: '',
        rating: 0,
        comment: '',
        client_name: '',
        client_contact: '',
        client_email: '',
        submitter_organization: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(links.submitFeedback, outcome(() => reset()));
    };

    const activeRating = hoveredRating ?? data.rating;
    // A refused rating (storage trouble, a replayed form) must say so: the rating form has no
    // "message" field of its own, so the server's general error would otherwise go unseen.
    const formError = errors.form_token ?? (errors as Record<string, string | undefined>).message;

    return (
        <FormScreen formRef={formRef} onSubmit={submit} requirements={requirements} processing={processing} submitLabel={t('serviceFeedback.submitFeedback')}>
            <FormNotice message={formError} />
            <TargetCard target={target} />

            {/* The rating is the whole point here: one large, centred question with big stars. */}
            <div className={`${cardClass} px-2 py-6 text-center`} data-field>
                <h2 className="text-lg font-bold text-[color:var(--app-foreground)]">{t('serviceFeedback.feedbackQuestion')}</h2>
                <div className="mt-4 flex items-center justify-center gap-1" role="radiogroup" aria-required="true" aria-label={t('serviceFeedback.satisfactionRating')} onMouseLeave={() => setHoveredRating(null)}>
                    {RATING_VALUES.map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="radio"
                            aria-checked={data.rating === value}
                            aria-label={t(`serviceFeedback.rating${value}`)}
                            onClick={() => {
                                setData('rating', value);
                                // The "choose a rating" error is answered the moment a star is picked.
                                clearErrors('rating');
                            }}
                            onMouseEnter={() => setHoveredRating(value)}
                            className="rounded-xl p-1 transition-transform active:scale-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                        >
                            <StarIcon filled={value <= activeRating} />
                        </button>
                    ))}
                </div>
                <p className={`mt-2 min-h-5 text-sm font-medium ${data.rating > 0 ? 'text-amber-700 dark:text-amber-300' : mutedClass}`} aria-live="polite">
                    {activeRating > 0 ? t(`serviceFeedback.rating${activeRating}`) : t('serviceFeedback.ratingHint')}
                </p>
                {errors.rating && <p role="alert" className={errorClass}>{errors.rating}</p>}
            </div>

            {target.target_type === 'employee' && (
                <ServiceSelect serviceTypes={serviceTypes} value={data.position_service_id} onChange={(value) => setData('position_service_id', value)} error={errors.position_service_id} optional={!requirements.service_required} />
            )}

            <CategoryChips categories={categories} value={data.category_id} onChange={(value) => setData('category_id', value)} error={errors.category_id} />

            <div className={cardClass} data-field>
                <label htmlFor="comment" className={labelClass}>
                    {t('serviceFeedback.comment')}
                    <RequirementMark required={requirements.narrative_required} />
                </label>
                <textarea id="comment" rows={4} maxLength={2000} required={requirements.narrative_required} aria-required={requirements.narrative_required} value={data.comment} onChange={(e) => { setData('comment', e.target.value); clearErrors('comment'); }} placeholder={t('serviceFeedback.commentPlaceholder')} className={areaClass} />
                {errors.comment && <p role="alert" className={errorClass}>{errors.comment}</p>}
            </div>

            <AboutYou requirements={requirements} data={data} set={(field, value) => { setData(field as 'client_name', value); clearErrors(field); }} errors={errors} />
        </FormScreen>
    );
}

/** The reference number with a copy button, where the browser allows copying. */
function ReferenceCard({ reference }: { reference: string }) {
    const { t } = useLocale();
    const [copied, setCopied] = useState(false);
    const canCopy = typeof navigator !== 'undefined' && Boolean(navigator.clipboard);

    const copy = () => {
        navigator.clipboard.writeText(reference).then(() => {
            setCopied(true);
            toast.success(t('serviceFeedback.referenceCopied'));
        }).catch(() => undefined);
    };

    return (
        <div className={`${cardClass} w-full text-left`}>
            <p className={`text-xs font-medium ${mutedClass}`}>{t('serviceFeedback.referenceNumber')}</p>
            <div className="mt-1 flex items-center justify-between gap-3">
                <span className="font-mono text-lg font-bold tracking-wide text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{reference}</span>
                {canCopy && (
                    <button type="button" onClick={copy} className="inline-flex min-h-11 shrink-0 items-center rounded-full border border-[color:var(--app-border-strong)] px-4 text-sm font-semibold text-[color:var(--app-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)]">
                        {copied ? t('common.copied') : t('common.copy')}
                    </button>
                )}
            </div>
        </div>
    );
}

/** The submission kinds the policy allows right now, in the order every screen lists them. */
function offeredKinds(enabledTypes: Props['enabledTypes']): SubmissionKind[] {
    return (['feedback', 'suggestion', 'complaint'] as const).filter((kind) => enabledTypes.includes(kind));
}

/**
 * The bottom navigation every screen of the flow carries, so the submissions
 * and Call stay under the thumb wherever the visitor is — a visitor who opened
 * the wrong form switches with one tap instead of going back first. The layout
 * fixes it to the bottom edge; on a form screen it marks the open form.
 */
function SubmissionDock({ links, enabledTypes, call, target, active }: {
    links: Links;
    enabledTypes: Props['enabledTypes'];
    call: PublicQrCall | null;
    target: TargetDisplay['target_type'];
    active?: SubmissionKind;
}) {
    return (
        <PublicQrActionDock
            hrefs={{ feedback: links.feedback, suggestion: links.suggestion, complaint: links.complaint }}
            offered={offeredKinds(enabledTypes)}
            call={call}
            target={target}
            active={active}
        />
    );
}

function ReceiptScreen({ kind, receipt, links, target, enabledTypes, call }: {
    kind: SubmissionKind;
    receipt: Receipt | null;
    links: Links;
    target: TargetDisplay;
    enabledTypes: Props['enabledTypes'];
    call: PublicQrCall | null;
}) {
    const { t } = useLocale();

    return (
        <FeedbackAppLayout
            title={t('serviceFeedback.publicTitle')}
            subtitle={receipt?.target_name}
            backHref={links.landing}
            backLabel={t('serviceFeedback.backToOptions')}
            dock={<SubmissionDock links={links} enabledTypes={enabledTypes} call={call} target={target.target_type} />}
        >
            <div className="flex flex-1 flex-col items-center px-5 pb-6 pt-10 text-center">
                <span className="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-500/15" aria-hidden="true">
                    <svg viewBox="0 0 24 24" className="h-10 w-10 text-emerald-600 dark:text-emerald-300" fill="none" stroke="currentColor" strokeWidth={2.5}>
                        <path d="M5 13l4 4L19 7" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </span>
                <h2 className="mt-5 text-xl font-bold text-[color:var(--app-foreground)]">{t(KINDS[kind].submitted)}</h2>
                <p className={`mt-2 max-w-xs text-sm leading-relaxed ${mutedClass}`}>{t(KINDS[kind].detail)}</p>

                <div className="mt-8 w-full space-y-3">
                    {receipt?.reference && <ReferenceCard reference={receipt.reference} />}
                    {/* The receipt (brief §42): what, about whom, the reference, and when. */}
                    <dl className={`${cardClass} space-y-3 text-left text-sm`}>
                        {receipt?.tip_type && (
                            <div className="flex justify-between gap-4">
                                <dt className={`shrink-0 ${mutedClass}`}>{t('serviceFeedback.tipTypeLabel')}</dt>
                                <dd className="text-right font-medium text-[color:var(--app-foreground)]">{t(TIP_TYPE_COPY[receipt.tip_type].label)}</dd>
                            </div>
                        )}
                        {receipt?.target_name && (
                            <div className="flex justify-between gap-4">
                                <dt className={`shrink-0 ${mutedClass}`}>{t('serviceFeedback.receiptAbout')}</dt>
                                <dd className="text-right font-medium text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{receipt.target_name}</dd>
                            </div>
                        )}
                        {receipt?.submitted_at && (
                            <div className="flex justify-between gap-4">
                                <dt className={`shrink-0 ${mutedClass}`}>{t('serviceFeedback.receiptSubmittedAt')}</dt>
                                <dd className="text-right font-medium text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={receipt.submitted_at} withTime /></dd>
                            </div>
                        )}
                    </dl>
                </div>
            </div>
            <FeedbackActionBar>
                <Link href={links.landing} className={`${publicButtonSecondary} min-h-[52px] w-full rounded-xl text-base`}>{t('serviceFeedback.submitAnother')}</Link>
            </FeedbackActionBar>
        </FeedbackAppLayout>
    );
}

function UnavailableScreen() {
    const { t } = useLocale();

    return (
        <FeedbackAppLayout title={t('serviceFeedback.publicTitle')}>
            <div className="flex flex-1 flex-col items-center justify-center px-8 py-16 text-center">
                <span className="flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300" aria-hidden="true">
                    <AlertTriangle className="h-8 w-8" />
                </span>
                <h2 className="mt-5 text-lg font-bold text-[color:var(--app-foreground)]">{t('serviceFeedback.linkUnavailable')}</h2>
                <p className={`mt-2 max-w-xs text-sm leading-relaxed ${mutedClass}`}>{t('serviceFeedback.linkUnavailableDetail')}</p>
            </div>
        </FeedbackAppLayout>
    );
}

function ChoiceScreen({ target, links, enabledTypes, call }: { target: TargetDisplay; links: Links; enabledTypes: Props['enabledTypes']; call: PublicQrCall | null }) {
    const { t } = useLocale();
    const organization = target.target_type === 'organization';
    const hrefs: Record<SubmissionKind, string> = { feedback: links.feedback, suggestion: links.suggestion, complaint: links.complaint };
    const offered = offeredKinds(enabledTypes);

    return (
        <FeedbackAppLayout
            title={t(organization ? 'serviceFeedback.publicLandingTitleOrganization' : 'serviceFeedback.publicLandingTitle')}
            dock={<SubmissionDock links={links} enabledTypes={enabledTypes} call={call} target={target.target_type} />}
        >
            <div className="flex flex-1 flex-col gap-6 px-4 pb-8 pt-5">
                {organization
                    ? <TargetCard target={target} intro={t('serviceFeedback.aboutInstitution')} />
                    : <EmployeeIdentityCard target={target} />}

                <section aria-labelledby="choose-type">
                    <h2 id="choose-type" className={`px-1 text-base font-bold text-[color:var(--app-foreground)] ${organization ? '' : 'text-center'}`}>
                        {t(organization ? 'serviceFeedback.chooseType' : 'serviceFeedback.employeeChoosePrompt')}
                    </h2>
                    <ul className="mt-3 space-y-3">
                        {offered.map((kind) => {
                            const { Icon, label, hint, tone } = KINDS[kind];

                            return (
                                <li key={kind}>
                                    <Link
                                        href={hrefs[kind]}
                                        className="flex min-h-[84px] items-center gap-4 rounded-2xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4 shadow-sm transition active:scale-[0.99] hover:border-[color:var(--color-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-primary)]"
                                    >
                                        <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ${tone}`} aria-hidden="true">
                                            <Icon className="h-6 w-6" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-base font-semibold text-[color:var(--app-foreground)]">{t(label)}</span>
                                            <span className={`mt-0.5 block text-sm leading-snug ${mutedClass}`}>{t(hint)}</span>
                                        </span>
                                        <ChevronRight className={`h-5 w-5 shrink-0 ${mutedClass}`} aria-hidden="true" />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </section>

                <p className={`mt-auto px-2 text-center text-xs leading-relaxed ${mutedClass}`}>{t('serviceFeedback.publicPrivacyNoteLanding')}</p>
            </div>
        </FeedbackAppLayout>
    );
}

export default function ServiceFeedback({ available, target, links, serviceTypes, categories = [], submitted, type = null, receipt = null, enabledTypes = ['feedback', 'suggestion', 'complaint'], requirements, evidence, tipTypes = ['CORRUPTION', 'IMPROPER_PRACTICE'], formToken = null, call = null }: Props) {
    const { t } = useLocale();

    /*
     * One page renders four screens — unavailable, receipt, choice and a form.
     * An unknown, suspended, revoked or disabled token lands here identically,
     * so a visitor cannot learn which tokens exist by comparing responses.
     */
    if (!available || target === null) {
        return <UnavailableScreen />;
    }

    if (submitted) {
        return <ReceiptScreen kind={receipt?.type ?? type ?? 'feedback'} receipt={receipt} links={links} target={target} enabledTypes={enabledTypes} call={call} />;
    }

    if (type === null) {
        return <ChoiceScreen target={target} links={links} enabledTypes={enabledTypes} call={call} />;
    }

    return (
        <FeedbackAppLayout
            title={t(KINDS[type].label)}
            subtitle={target.display_name}
            backHref={links.landing}
            backLabel={t('serviceFeedback.backToOptions')}
            dock={<SubmissionDock links={links} enabledTypes={enabledTypes} call={call} target={target.target_type} active={type} />}
        >
            {type === 'feedback' && <RatingForm target={target} serviceTypes={serviceTypes} categories={categories} links={links} formToken={formToken} requirements={requirements.feedback} />}
            {type === 'suggestion' && <TipOffForm target={target} serviceTypes={serviceTypes} categories={categories} links={links} formToken={formToken} requirements={requirements.suggestion} evidence={evidence} tipTypes={tipTypes} />}
            {type === 'complaint' && <ComplaintForm target={target} serviceTypes={serviceTypes} categories={categories} links={links} formToken={formToken} requirements={requirements.complaint} evidence={evidence} />}
        </FeedbackAppLayout>
    );
}
