import { FormEvent, RefObject, useEffect, useRef, useState } from 'react';
import { controlClassName } from '@euisis/ui';
import type { Page, VisitOptions } from '@inertiajs/core';
import { PublicPageHeader, publicButtonPrimary } from '@/Components/public/PublicPage';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import MultiFileUpload from '@/Components/public/MultiFileUpload';
import { Link, router, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';

/**
 * Public Feedback, Suggestion and Complaint.
 *
 * Reached by scanning an employee's feedback QR or an institution's feedback
 * QR with any phone camera, so the page is mobile-first and needs no login,
 * no app and no camera permission.
 *
 * The page never decides WHO a submission is about. The server resolved the
 * scanned token to a target and sends only what a visitor may see of it: a
 * masked role, office and unit for an employee; name and logo for an
 * institution. Every URL comes from the server too (`links`), so the page
 * assembles none and cannot point a form at a different target.
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
    display_name: string;
    organization: string | null;
    organization_unit: string | null;
    position: string | null;
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
    type: 'feedback' | 'suggestion' | 'complaint';
    target_type: 'employee' | 'organization';
    target_name: string;
    reference: string | null;
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
    enabledTypes: Array<'feedback' | 'suggestion' | 'complaint'>;
    /** What is asked of the submitter: nothing, name and contact, or both offered (brief §34). */
    identityMode: 'anonymous' | 'identified' | 'optional';
    allowAttachments: boolean;
}

type IdentityMode = Props['identityMode'];

const errorClass = 'mt-1.5 text-sm text-red-600 dark:text-red-400';
const cardClass = 'rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';

/** Office · unit, without repeating the role the heading already names. */
function contextLine(target: TargetDisplay): string {
    return [target.organization, target.organization_unit, target.position]
        .filter((part): part is string => Boolean(part) && part !== target.display_name)
        .join(' · ');
}

/** The target as the visitor may see it: a desk, or an institution with its logo. */
function TargetCard({ target, intro, heading }: { target: TargetDisplay; intro?: string; heading?: boolean }) {
    const Title = heading ? 'h2' : 'p';

    return (
        <div className={cardClass}>
            <div className="flex items-center gap-4">
                {target.target_type === 'organization' && target.logo_url && (
                    <img src={target.logo_url} alt="" className="h-14 w-14 shrink-0 rounded-lg border border-slate-200 bg-white object-contain p-1 dark:border-slate-700" />
                )}
                <div className="min-w-0">
                    <Title className="font-semibold text-slate-900 [overflow-wrap:anywhere] dark:text-slate-100">{target.display_name}</Title>
                    {target.target_type === 'employee' && contextLine(target) !== '' && (
                        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{contextLine(target)}</p>
                    )}
                    {target.target_type === 'organization' && target.short_name && (
                        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{target.short_name}</p>
                    )}
                </div>
            </div>
            {intro && <p className="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-400">{intro}</p>}
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
        <div>
            <label htmlFor="position_service_id" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                {t('serviceFeedback.serviceTypeOrId')}
                {optional && <span className="ml-1 font-normal text-slate-400">({t('serviceFeedback.clientNameHint')})</span>}
            </label>
            {serviceTypes.length === 0 && (
                <p className="mt-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {t('serviceFeedback.noServiceTypesConfigured')}
                </p>
            )}
            <select id="position_service_id" value={value} onChange={(e) => onChange(e.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`}>
                <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                {serviceTypes.map((service) => (
                    <option key={service.id} value={service.id}>{service.service_no ? `${service.service_no} — ${service.name}` : service.name}</option>
                ))}
            </select>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

function CategorySelect({ categories, value, onChange, error }: { categories: CategoryOption[]; value: string; onChange: (value: string) => void; error?: string }) {
    const { t } = useLocale();

    if (categories.length === 0) return null;

    return (
        <div>
            <label htmlFor="category_id" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                {t('serviceFeedback.category')}
                <span className="ml-1 font-normal text-slate-400">({t('serviceFeedback.clientNameHint')})</span>
            </label>
            <select id="category_id" value={value} onChange={(e) => onChange(e.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`}>
                <option value="">{t('serviceFeedback.categoryPlaceholder')}</option>
                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
            </select>
            {error && <p role="alert" className={errorClass}>{error}</p>}
        </div>
    );
}

type SubmissionKind = 'feedback' | 'suggestion' | 'complaint';

const SUBMITTED_KEY: Record<SubmissionKind, string> = {
    feedback: 'serviceFeedback.feedbackSubmitted',
    suggestion: 'serviceFeedback.suggestionSubmitted',
    complaint: 'serviceFeedback.complaintSubmitted',
};

/** Long enough to read an Amharic sentence on a phone before the popup goes. */
const ERROR_TOAST_MS = 9000;

/**
 * Scroll the first error into the middle of the screen and put the cursor in
 * its field. On a phone the form is longer than the screen, so a field error
 * rendered above the Send button was otherwise never seen.
 */
function revealFirstError(form: HTMLFormElement | null): void {
    if (!form) return;
    // Wait for React to paint the errors that came back with the response.
    window.requestAnimationFrame(() => {
        const alert = form.querySelector<HTMLElement>('[role="alert"]');
        if (!alert) return;
        alert.scrollIntoView({ block: 'center', behavior: 'smooth' });
        const group = alert.parentElement;
        if (group && group !== form) {
            group.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea, [role="radio"]')?.focus({ preventScroll: true });
        }
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
function useSubmissionOutcome(kind: SubmissionKind, formRef: RefObject<HTMLFormElement | null>) {
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

/** The footer promise must match what the form asks for (brief §34). */
function privacyNoteKey(mode: IdentityMode): string {
    return mode === 'identified' ? 'serviceFeedback.publicPrivacyNoteIdentified' : mode === 'anonymous' ? 'serviceFeedback.publicPrivacyNoteAnonymous' : 'serviceFeedback.publicPrivacyNote';
}

function ClientFields({ name, contact, onName, onContact, errors, mode }: {
    name: string;
    contact: string;
    onName: (value: string) => void;
    onContact: (value: string) => void;
    errors: { client_name?: string; client_contact?: string };
    mode: IdentityMode;
}) {
    const { t } = useLocale();

    // Anonymous: nothing is asked and nothing is kept (brief §34).
    if (mode === 'anonymous') {
        return <p className="border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-400">{t('serviceFeedback.anonymousNotice')}</p>;
    }
    const required = mode === 'identified';
    const hint = required ? t('serviceFeedback.requiredHint') : t('serviceFeedback.clientNameHint');

    return (
        <div className="grid gap-4 border-t border-slate-100 pt-4 dark:border-slate-800">
            <div>
                <label htmlFor="client_name" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                    {t('serviceFeedback.clientName')}
                    <span className="ml-1 font-normal text-slate-400">({hint})</span>
                </label>
                <input id="client_name" type="text" maxLength={120} required={required} value={name} onChange={(e) => onName(e.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                {errors.client_name && <p role="alert" className={errorClass}>{errors.client_name}</p>}
            </div>
            <div>
                <label htmlFor="client_contact" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                    {t('serviceFeedback.clientContact')}
                    <span className="ml-1 font-normal text-slate-400">({required ? t('serviceFeedback.requiredHint') : t('serviceFeedback.clientContactHint')})</span>
                </label>
                <input id="client_contact" type="text" maxLength={120} required={required} value={contact} onChange={(e) => onContact(e.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                {errors.client_contact && <p role="alert" className={errorClass}>{errors.client_contact}</p>}
            </div>
        </div>
    );
}

function ServiceEntryForm({ type, target, serviceTypes, categories, links, formToken, identityMode, allowAttachments }: {
    type: 'suggestion' | 'complaint';
    target: TargetDisplay;
    serviceTypes: ServiceTypeOption[];
    categories: CategoryOption[];
    links: Links;
    formToken: string | null;
    identityMode: IdentityMode;
    allowAttachments: boolean;
}) {
    const { t } = useLocale();
    const formRef = useRef<HTMLFormElement>(null);
    const outcome = useSubmissionOutcome(type, formRef);
    const { data, setData, post, processing, errors } = useForm({
        form_token: formToken ?? '',
        position_service_id: '', category_id: '', message: '', client_name: '', client_contact: '',
        incident_date: '', incident_location: '', attachments: [] as File[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(type === 'complaint' ? links.submitComplaint : links.submitSuggestion, { forceFormData: true, ...outcome() });
    };

    return (
        <form ref={formRef} onSubmit={submit} noValidate className={`mt-4 space-y-5 ${cardClass}`}>
            <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">{t(type === 'suggestion' ? 'serviceFeedback.typeSuggestion' : 'serviceFeedback.typeComplaint')}</h2>
            {errors.form_token && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{errors.form_token}</p>}
            {target.target_type === 'employee' && (
                <ServiceSelect serviceTypes={serviceTypes} value={data.position_service_id} onChange={(value) => setData('position_service_id', value)} error={errors.position_service_id} optional />
            )}
            <CategorySelect categories={categories} value={data.category_id} onChange={(value) => setData('category_id', value)} error={errors.category_id} />
            <div>
                <label htmlFor="entry-message" className="block text-sm font-medium">{t(type === 'complaint' ? 'serviceFeedback.complaintDescription' : 'serviceFeedback.suggestionText')}</label>
                <textarea id="entry-message" required maxLength={5000} rows={6} value={data.message} onChange={(event) => setData('message', event.target.value)} className={`${controlClassName} mt-1.5 h-auto min-h-32 py-2 text-base`} />
                {errors.message && <p role="alert" className={errorClass}>{errors.message}</p>}
            </div>
            {type === 'complaint' && <>
                <div><label htmlFor="incident-date" className="block text-sm font-medium">{t('serviceFeedback.incidentDate')} ({t('serviceFeedback.clientNameHint')})</label>
                    <div className="mt-1.5"><LocalizedDatePicker id="incident-date" value={data.incident_date} onChange={(iso) => setData('incident_date', iso)} /></div>
                    {errors.incident_date && <p role="alert" className={errorClass}>{errors.incident_date}</p>}</div>
                <div><label htmlFor="incident-location" className="block text-sm font-medium">{t('serviceFeedback.incidentLocation')} ({t('serviceFeedback.clientNameHint')})</label>
                    <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => setData('incident_location', event.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                    {errors.incident_location && <p role="alert" className={errorClass}>{errors.incident_location}</p>}</div>
                {allowAttachments && <div><label htmlFor="complaint-attachments" className="block text-sm font-medium">{t('serviceFeedback.attachments')} ({t('serviceFeedback.clientNameHint')})</label>
                    <p id="attachment-hint" className="mt-1 text-xs text-slate-600 dark:text-slate-400">{t('serviceFeedback.attachmentHint')}</p>
                    <div className="mt-2"><MultiFileUpload id="complaint-attachments" files={data.attachments} onChange={(files) => setData('attachments', files)} accept=".pdf,.jpg,.jpeg,.png" max={3} chooseLabel={t('serviceFeedback.chooseFiles')} removeLabel={t('serviceFeedback.removeFile')} describedBy="attachment-hint" /></div>
                    {errors.attachments && <p role="alert" className={errorClass}>{errors.attachments}</p>}</div>}
            </>}
            <ClientFields name={data.client_name} contact={data.client_contact} onName={(v) => setData('client_name', v)} onContact={(v) => setData('client_contact', v)} errors={errors} mode={identityMode} />
            <button type="submit" disabled={processing} className={`${publicButtonPrimary} min-h-[48px] w-full text-base`}>
                {processing ? t('serviceFeedback.submitting') : t(type === 'complaint' ? 'serviceFeedback.submitComplaint' : 'serviceFeedback.submitSuggestion')}
            </button>
            <p className="text-center text-xs text-slate-500 dark:text-slate-400">{t(privacyNoteKey(identityMode))}</p>
        </form>
    );
}

const RATING_VALUES = [1, 2, 3, 4, 5] as const;

function StarIcon({ filled }: { filled: boolean }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className={`h-10 w-10 transition-colors sm:h-9 sm:w-9 ${filled ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600'}`} fill="currentColor">
            <path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
        </svg>
    );
}

function RatingForm({ target, serviceTypes, categories, links, formToken, identityMode }: { identityMode: IdentityMode; target: TargetDisplay; serviceTypes: ServiceTypeOption[]; categories: CategoryOption[]; links: Links; formToken: string | null }) {
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
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(links.submitFeedback, outcome(() => reset()));
    };

    const activeRating = hoveredRating ?? data.rating;

    return (
        <form ref={formRef} onSubmit={submit} noValidate className={`mt-4 space-y-5 ${cardClass}`}>
            {/* A refused rating (storage trouble, a replayed form) must say so: the rating form has no
                "message" field of its own, so the server's general error would otherwise go unseen. */}
            {(errors.form_token || (errors as Record<string, string | undefined>).message) && (
                <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                    {errors.form_token ?? (errors as Record<string, string | undefined>).message}
                </p>
            )}
            {target.target_type === 'employee' && (
                <ServiceSelect serviceTypes={serviceTypes} value={data.position_service_id} onChange={(value) => setData('position_service_id', value)} error={errors.position_service_id} optional={false} />
            )}
            <CategorySelect categories={categories} value={data.category_id} onChange={(value) => setData('category_id', value)} error={errors.category_id} />

            {/* Star rating — large touch targets for phone use. */}
            <div>
                <span className="block text-sm font-medium text-slate-900 dark:text-slate-100">{t('serviceFeedback.satisfactionRating')}</span>
                <div className="mt-2 flex items-center gap-1" role="radiogroup" aria-label={t('serviceFeedback.satisfactionRating')} onMouseLeave={() => setHoveredRating(null)}>
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
                            className="rounded-md p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                        >
                            <StarIcon filled={value <= activeRating} />
                        </button>
                    ))}
                </div>
                <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                    {data.rating > 0 ? t(`serviceFeedback.rating${data.rating}`) : t('serviceFeedback.ratingHint')}
                </p>
                {errors.rating && <p role="alert" className={errorClass}>{errors.rating}</p>}
            </div>

            <div>
                <label htmlFor="comment" className="block text-sm font-medium text-slate-900 dark:text-slate-100">{t('serviceFeedback.comment')}</label>
                <textarea id="comment" rows={4} maxLength={2000} value={data.comment} onChange={(e) => setData('comment', e.target.value)} placeholder={t('serviceFeedback.commentPlaceholder')} className={`${controlClassName} mt-1.5 h-auto min-h-28 py-2 text-base`} />
                {errors.comment && <p role="alert" className={errorClass}>{errors.comment}</p>}
            </div>

            <ClientFields name={data.client_name} contact={data.client_contact} onName={(v) => setData('client_name', v)} onContact={(v) => setData('client_contact', v)} errors={errors} mode={identityMode} />

            <button type="submit" disabled={processing} className={`${publicButtonPrimary} min-h-[48px] w-full text-base`}>
                {processing ? t('serviceFeedback.submitting') : t('serviceFeedback.submitFeedback')}
            </button>
            <p className="text-center text-xs text-slate-500 dark:text-slate-400">{t(privacyNoteKey(identityMode))}</p>
        </form>
    );
}

function Shell({ children }: { children: React.ReactNode }) {
    const { t } = useLocale();

    // An expired form (419) sends the visitor back here with a notice. The layout shows it as a
    // popup, which is seen wherever the page is scrolled; a banner at the top was not, on a phone.
    return (
        <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />
            {children}
        </PublicLayout>
    );
}

export default function ServiceFeedback({ available, target, links, serviceTypes, categories = [], submitted, type = null, receipt = null, enabledTypes = ['feedback', 'suggestion', 'complaint'], identityMode = 'optional', allowAttachments = true, formToken = null }: Props) {
    const { t } = useLocale();

    /*
     * One page renders four states — unavailable, receipt, landing and a form.
     * An unknown, suspended, revoked or disabled token lands here identically,
     * so a visitor cannot learn which tokens exist by comparing responses.
     */
    if (!available || target === null) {
        return (
            <Shell>
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className={`${cardClass} text-center`}>
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">{t('serviceFeedback.linkUnavailable')}</h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">{t('serviceFeedback.linkUnavailableDetail')}</p>
                    </div>
                </div>
            </Shell>
        );
    }

    if (submitted) {
        const kind = receipt?.type ?? type ?? 'feedback';

        return (
            <Shell>
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className="rounded-card border border-emerald-200 bg-emerald-50 p-6 text-center dark:border-emerald-900 dark:bg-emerald-950/40">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900">
                            <svg viewBox="0 0 24 24" className="h-6 w-6 text-emerald-700 dark:text-emerald-300" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                <path d="M5 13l4 4L19 7" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        </div>
                        <h2 className="mt-4 text-lg font-semibold text-emerald-900 dark:text-emerald-100">
                            {t(kind === 'complaint' ? 'serviceFeedback.complaintSubmitted' : kind === 'suggestion' ? 'serviceFeedback.suggestionSubmitted' : 'serviceFeedback.feedbackSubmitted')}
                        </h2>
                        <p className="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                            {t(kind === 'complaint' ? 'serviceFeedback.complaintSubmittedDetail' : kind === 'suggestion' ? 'serviceFeedback.suggestionSubmittedDetail' : 'serviceFeedback.feedbackSubmittedDetail')}
                        </p>
                        {/* The receipt (brief §42): what, about whom, the reference, and when. */}
                        <dl className="mt-4 space-y-1 border-t border-emerald-200 pt-3 text-left text-sm dark:border-emerald-900">
                            {receipt?.target_name && (
                                <div className="flex justify-between gap-3"><dt className="shrink-0 text-emerald-800 dark:text-emerald-300">{t('serviceFeedback.receiptAbout')}</dt><dd className="text-right font-medium text-emerald-900 dark:text-emerald-100">{receipt.target_name}</dd></div>
                            )}
                            {receipt?.reference && (
                                <div className="flex justify-between gap-3"><dt className="shrink-0 text-emerald-800 dark:text-emerald-300">{t('serviceFeedback.referenceNumber')}</dt><dd className="text-right font-mono font-semibold text-emerald-900 dark:text-emerald-100">{receipt.reference}</dd></div>
                            )}
                            {receipt?.submitted_at && (
                                <div className="flex justify-between gap-3"><dt className="shrink-0 text-emerald-800 dark:text-emerald-300">{t('serviceFeedback.receiptSubmittedAt')}</dt><dd className="text-right font-medium text-emerald-900 dark:text-emerald-100"><LocalizedDateDisplay value={receipt.submitted_at} withTime /></dd></div>
                            )}
                        </dl>
                        <Link href={links.landing} className="mt-5 inline-flex min-h-11 items-center font-semibold text-emerald-800 underline underline-offset-4 focus-visible:outline-2 dark:text-emerald-300">{t('serviceFeedback.submitAnother')}</Link>
                    </div>
                </div>
            </Shell>
        );
    }

    if (type === null) {
        const options = [
            { id: 'feedback', label: 'typeFeedback', href: links.feedback },
            { id: 'suggestion', label: 'typeSuggestion', href: links.suggestion },
            { id: 'complaint', label: 'typeComplaint', href: links.complaint },
        ] as const;
        const offered = options.filter((option) => enabledTypes.includes(option.id));

        return (
            <Shell>
                <div className="mx-auto max-w-lg px-4 py-8">
                    <h2 className="sr-only">{t(target.target_type === 'organization' ? 'serviceFeedback.publicLandingTitleOrganization' : 'serviceFeedback.publicLandingTitle')}</h2>
                    <TargetCard target={target} heading intro={t(target.target_type === 'organization' ? 'serviceFeedback.aboutInstitution' : 'serviceFeedback.publicIntro')} />
                    <nav aria-label={t('serviceFeedback.chooseType')} className="mt-5 grid gap-3">
                        {offered.map((option) => (
                            <Link key={option.id} href={option.href} className="flex min-h-20 items-center justify-between rounded-card border-2 border-slate-200 bg-white px-5 text-lg font-semibold text-slate-900 transition-colors hover:border-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                {t(`serviceFeedback.${option.label}`)} <span aria-hidden="true">→</span>
                            </Link>
                        ))}
                    </nav>
                </div>
            </Shell>
        );
    }

    return (
        <Shell>
            <div className="mx-auto max-w-md px-4 py-8">
                <Link href={links.landing} className="mb-3 inline-flex min-h-11 items-center text-sm font-semibold text-emerald-800 underline underline-offset-4 dark:text-emerald-300">{t('serviceFeedback.backToOptions')}</Link>
                <TargetCard target={target} />
                {type === 'feedback'
                    ? <RatingForm target={target} serviceTypes={serviceTypes} categories={categories} links={links} formToken={formToken} identityMode={identityMode} />
                    : <ServiceEntryForm type={type} target={target} serviceTypes={serviceTypes} categories={categories} links={links} formToken={formToken} identityMode={identityMode} allowAttachments={allowAttachments} />}
            </div>
        </Shell>
    );
}
