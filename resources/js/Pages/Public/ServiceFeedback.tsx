import { FormEvent, useState } from 'react';
import { controlClassName } from '@euisis/ui';
import { PublicPageHeader, publicButtonPrimary } from '@/Components/public/PublicPage';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import MultiFileUpload from '@/Components/public/MultiFileUpload';
import { Link, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { useLocale } from '@/hooks/useLocale';

/**
 * Public Client Service Feedback.
 *
 * Reached by scanning an employee's feedback QR with any phone camera, so the
 * page is mobile-first and needs no login, no app and no camera permission.
 *
 * Note what this page CANNOT receive: there is no employee name, number, phone,
 * email or photo in `Props`. The server sends a masked role label plus the
 * office context, which is enough for a client to confirm they are rating the
 * right desk without turning a printed QR into a public directory lookup.
 */

interface ServiceTypeOption {
    id: string;
    /** Position-scoped Service ID, shown to the client alongside the name. */
    service_no: string;
    name: string;
}

/** Everything the public page is permitted to know about the service point. */
interface PublicContext {
    display_name: string;
    organization: string | null;
    organization_unit: string | null;
    position: string | null;
}

interface Props {
    available: boolean;
    token: string;
    context: PublicContext | null;
    serviceTypes: ServiceTypeOption[];
    submitted: boolean;
    type: 'feedback' | 'suggestion' | 'complaint' | null;
    reference: string | null;
}

function ServiceEntryForm({ token, type, serviceTypes }: { token: string; type: 'suggestion' | 'complaint'; serviceTypes: ServiceTypeOption[] }) {
    const { t } = useLocale();
    const { data, setData, post, processing, errors } = useForm({
        position_service_id: '', message: '', client_name: '', client_contact: '',
        incident_date: '', incident_location: '', attachments: [] as File[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/service-feedback/${token}/${type}`, { forceFormData: true, preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-5 rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">{t(`serviceFeedback.type${type === 'suggestion' ? 'Suggestion' : 'Complaint'}`)}</h2>
            <div>
                <label htmlFor="entry-service" className="block text-sm font-medium">{t('serviceFeedback.serviceTypeOrId')} ({t('serviceFeedback.clientNameHint')})</label>
                <select id="entry-service" value={data.position_service_id} onChange={(event) => setData('position_service_id', event.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`}>
                    <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                    {serviceTypes.map((service) => <option key={service.id} value={service.id}>{service.service_no} — {service.name}</option>)}
                </select>
                {errors.position_service_id && <p role="alert" className="mt-1 text-sm text-red-600">{errors.position_service_id}</p>}
            </div>
            <div>
                <label htmlFor="entry-message" className="block text-sm font-medium">{t(type === 'complaint' ? 'serviceFeedback.complaintDescription' : 'serviceFeedback.suggestionText')}</label>
                <textarea id="entry-message" required maxLength={5000} rows={6} value={data.message} onChange={(event) => setData('message', event.target.value)} className={`${controlClassName} mt-1.5 h-auto min-h-32 py-2 text-base`} />
                {errors.message && <p role="alert" className="mt-1 text-sm text-red-600">{errors.message}</p>}
            </div>
            {type === 'complaint' && <>
                <div><label htmlFor="incident-date" className="block text-sm font-medium">{t('serviceFeedback.incidentDate')} ({t('serviceFeedback.clientNameHint')})</label>
                    <div className="mt-1.5"><LocalizedDatePicker id="incident-date" value={data.incident_date} onChange={(iso) => setData('incident_date', iso)} /></div>
                    {errors.incident_date && <p role="alert" className="mt-1 text-sm text-red-600">{errors.incident_date}</p>}</div>
                <div><label htmlFor="incident-location" className="block text-sm font-medium">{t('serviceFeedback.incidentLocation')} ({t('serviceFeedback.clientNameHint')})</label>
                    <input id="incident-location" maxLength={255} value={data.incident_location} onChange={(event) => setData('incident_location', event.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                    {errors.incident_location && <p role="alert" className="mt-1 text-sm text-red-600">{errors.incident_location}</p>}</div>
                <div><label htmlFor="complaint-attachments" className="block text-sm font-medium">{t('serviceFeedback.attachments')} ({t('serviceFeedback.clientNameHint')})</label>
                    <p id="attachment-hint" className="mt-1 text-xs text-slate-600 dark:text-slate-400">{t('serviceFeedback.attachmentHint')}</p>
                    <div className="mt-2"><MultiFileUpload id="complaint-attachments" files={data.attachments} onChange={(files) => setData('attachments', files)} accept=".pdf,.jpg,.jpeg,.png" max={3} chooseLabel={t('serviceFeedback.chooseFiles')} removeLabel={t('serviceFeedback.removeFile')} describedBy="attachment-hint" /></div>
                    {errors.attachments && <p role="alert" className="mt-1 text-sm text-red-600">{errors.attachments}</p>}</div>
            </>}
            <div className="grid gap-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                <div><label htmlFor="entry-name" className="block text-sm font-medium">{t('serviceFeedback.clientName')} ({t('serviceFeedback.clientNameHint')})</label>
                    <input id="entry-name" maxLength={120} value={data.client_name} onChange={(event) => setData('client_name', event.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                    {errors.client_name && <p role="alert" className="mt-1 text-sm text-red-600">{errors.client_name}</p>}</div>
                <div><label htmlFor="entry-contact" className="block text-sm font-medium">{t('serviceFeedback.clientContact')} ({t('serviceFeedback.clientContactHint')})</label>
                    <input id="entry-contact" maxLength={120} value={data.client_contact} onChange={(event) => setData('client_contact', event.target.value)} className={`${controlClassName} mt-1.5 h-11 text-base`} />
                    {errors.client_contact && <p role="alert" className="mt-1 text-sm text-red-600">{errors.client_contact}</p>}</div>
            </div>
            <button type="submit" disabled={processing} className={`${publicButtonPrimary} min-h-[48px] w-full text-base`}>
                {processing ? t('serviceFeedback.submitting') : t(type === 'complaint' ? 'serviceFeedback.submitComplaint' : 'serviceFeedback.submitSuggestion')}
            </button>
            <p className="text-center text-xs text-slate-500 dark:text-slate-400">{t('serviceFeedback.publicPrivacyNote')}</p>
        </form>
    );
}

/** Office · unit, without repeating the role the heading already names. */
function contextLine(context: PublicContext | null): string {
    return [context?.organization, context?.organization_unit, context?.position]
        .filter((part): part is string => Boolean(part) && part !== context?.display_name)
        .join(' · ');
}

const RATING_VALUES = [1, 2, 3, 4, 5] as const;

function StarIcon({ filled }: { filled: boolean }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            className={`h-10 w-10 transition-colors sm:h-9 sm:w-9 ${
                filled ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600'
            }`}
            fill="currentColor"
        >
            <path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
        </svg>
    );
}

export default function ServiceFeedback({ available, token, context, serviceTypes, submitted, type = null, reference = null }: Props) {
    const { t } = useLocale();

    /*
     * Hover preview is tracked separately from the committed rating so the
     * stars light up under a mouse without changing the value until a click.
     * Touch devices never set this, so they see only the committed state.
     */
    const [hoveredRating, setHoveredRating] = useState<number | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        position_service_id: '',
        rating: 0,
        comment: '',
        client_name: '',
        client_contact: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/service-feedback/${token}/feedback`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    /*
     * One page renders three states — unavailable, thank-you and the form.
     * An unknown, suspended and revoked token all land here identically, so a
     * visitor cannot learn which tokens exist by comparing responses.
     */
    if (!available) {
        return (
            <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className="rounded-card border border-slate-200 bg-white p-6 text-center dark:border-slate-700 dark:bg-slate-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                            {t('serviceFeedback.linkUnavailable')}
                        </h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            {t('serviceFeedback.linkUnavailableDetail')}
                        </p>
                    </div>
                </div>
            </PublicLayout>
        );
    }

    if (submitted) {
        return (
            <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className="rounded-card border border-emerald-200 bg-emerald-50 p-6 text-center dark:border-emerald-900 dark:bg-emerald-950/40">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900">
                            <svg viewBox="0 0 24 24" className="h-6 w-6 text-emerald-700 dark:text-emerald-300" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                <path d="M5 13l4 4L19 7" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        </div>
                        <h2 className="mt-4 text-lg font-semibold text-emerald-900 dark:text-emerald-100">
                            {t(type === 'complaint' ? 'serviceFeedback.complaintSubmitted' : type === 'suggestion' ? 'serviceFeedback.suggestionSubmitted' : 'serviceFeedback.feedbackSubmitted')}
                        </h2>
                        <p className="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                            {t(type === 'complaint' ? 'serviceFeedback.complaintSubmittedDetail' : type === 'suggestion' ? 'serviceFeedback.suggestionSubmittedDetail' : 'serviceFeedback.feedbackSubmittedDetail')}
                        </p>
                        {reference && <p className="mt-3 font-mono text-base font-semibold text-emerald-900 dark:text-emerald-100">{t('serviceFeedback.referenceNumber')}: {reference}</p>}
                        <Link href={`/service-feedback/${token}`} className="mt-5 inline-flex min-h-11 items-center font-semibold text-emerald-800 underline underline-offset-4 focus-visible:outline-2">{t('serviceFeedback.submitAnother')}</Link>
                    </div>
                </div>
            </PublicLayout>
        );
    }

    if (type === null) {
        const options = [
            { id: 'feedback', label: 'typeFeedback' },
            { id: 'suggestion', label: 'typeSuggestion' },
            { id: 'complaint', label: 'typeComplaint' },
        ] as const;

        return <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />
            <div className="mx-auto max-w-lg px-4 py-8">
                <div className="rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <h2 className="text-2xl font-semibold text-slate-900 dark:text-slate-100">{t('serviceFeedback.publicLandingTitle')}</h2>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">{t('serviceFeedback.publicIntro')}</p>
                    <p className="mt-4 border-t border-slate-200 pt-4 text-sm font-medium text-slate-900 dark:border-slate-700 dark:text-slate-100">{context?.display_name}</p>
                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{contextLine(context)}</p>
                </div>
                <nav aria-label={t('serviceFeedback.chooseType')} className="mt-5 grid gap-3">
                    {options.map((option) => <Link key={option.id} href={`/service-feedback/${token}/${option.id}`} className="flex min-h-20 items-center justify-between rounded-card border-2 border-slate-200 bg-white px-5 text-lg font-semibold text-slate-900 transition-colors hover:border-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        {t(`serviceFeedback.${option.label}`)} <span aria-hidden="true">→</span>
                    </Link>)}
                </nav>
            </div>
        </PublicLayout>;
    }

    if (type === 'suggestion' || type === 'complaint') {
        return <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />
            <div className="mx-auto max-w-md px-4 py-8">
                <Link href={`/service-feedback/${token}`} className="inline-flex min-h-11 items-center text-sm font-semibold text-emerald-800 underline underline-offset-4 dark:text-emerald-300">{t('serviceFeedback.backToOptions')}</Link>
                <div className="mt-3 rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <p className="font-semibold text-slate-900 dark:text-slate-100">{context?.display_name}</p>
                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{contextLine(context)}</p>
                </div>
                <ServiceEntryForm token={token} type={type} serviceTypes={serviceTypes} />
            </div>
        </PublicLayout>;
    }

    const activeRating = hoveredRating ?? data.rating;

    return (
        <PublicLayout title={t('serviceFeedback.publicTitle')} noindex>
            <PublicPageHeader title={t('serviceFeedback.publicTitle')} compact />

            <div className="mx-auto max-w-md px-4 py-8">
                <Link href={`/service-feedback/${token}`} className="mb-3 inline-flex min-h-11 items-center text-sm font-semibold text-emerald-800 underline underline-offset-4 dark:text-emerald-300">{t('serviceFeedback.backToOptions')}</Link>
                {/* Service point context — office and role only, never a person. */}
                <div className="rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {context?.display_name}
                    </h2>
                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        {t('serviceFeedback.publicIntro')}
                    </p>

                    <dl className="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                        {context?.organization && (
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500 dark:text-slate-400">{t('serviceFeedback.serviceOffice')}</dt>
                                <dd className="text-right font-medium text-slate-900 dark:text-slate-100">{context.organization}</dd>
                            </div>
                        )}
                        {context?.organization_unit && (
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500 dark:text-slate-400">{t('serviceFeedback.serviceUnit')}</dt>
                                <dd className="text-right font-medium text-slate-900 dark:text-slate-100">{context.organization_unit}</dd>
                            </div>
                        )}
                        {context?.position && (
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500 dark:text-slate-400">{t('serviceFeedback.servicePosition')}</dt>
                                <dd className="text-right font-medium text-slate-900 dark:text-slate-100">{context.position}</dd>
                            </div>
                        )}
                    </dl>
                </div>

                <form onSubmit={submit} className="mt-4 space-y-5 rounded-card border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    {/* Service type */}
                    <div>
                        <label htmlFor="position_service_id" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                            {t('serviceFeedback.serviceTypeOrId')}
                        </label>
                        {serviceTypes.length === 0 && (
                            <p className="mt-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                {t('serviceFeedback.noServiceTypesConfigured')}
                            </p>
                        )}
                        <select
                            id="position_service_id"
                            value={data.position_service_id}
                            onChange={(e) => setData('position_service_id', e.target.value)}
                            className={`${controlClassName} mt-1.5 h-11 text-base`}
                        >
                            <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                            {serviceTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.service_no ? `${type.service_no} — ${type.name}` : type.name}
                                </option>
                            ))}
                        </select>
                        {errors.position_service_id && (
                            <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.position_service_id}</p>
                        )}
                    </div>

                    {/* Star rating — large touch targets for phone use. */}
                    <div>
                        <span className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                            {t('serviceFeedback.satisfactionRating')}
                        </span>
                        <div
                            className="mt-2 flex items-center gap-1"
                            role="radiogroup"
                            aria-label={t('serviceFeedback.satisfactionRating')}
                            onMouseLeave={() => setHoveredRating(null)}
                        >
                            {RATING_VALUES.map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.rating === value}
                                    aria-label={t(`serviceFeedback.rating${value}`)}
                                    onClick={() => setData('rating', value)}
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
                        {errors.rating && <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.rating}</p>}
                    </div>

                    {/* Comment */}
                    <div>
                        <label htmlFor="comment" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                            {t('serviceFeedback.comment')}
                        </label>
                        <textarea
                            id="comment"
                            rows={4}
                            maxLength={2000}
                            value={data.comment}
                            onChange={(e) => setData('comment', e.target.value)}
                            placeholder={t('serviceFeedback.commentPlaceholder')}
                            className={`${controlClassName} mt-1.5 h-auto min-h-28 py-2 text-base`}
                        />
                        {errors.comment && <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.comment}</p>}
                    </div>

                    {/* Optional identity. Left blank, the submission stays anonymous. */}
                    <div className="grid gap-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <div>
                            <label htmlFor="client_name" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                                {t('serviceFeedback.clientName')}
                                <span className="ml-1 font-normal text-slate-400">({t('serviceFeedback.clientNameHint')})</span>
                            </label>
                            <input
                                id="client_name"
                                type="text"
                                maxLength={120}
                                value={data.client_name}
                                onChange={(e) => setData('client_name', e.target.value)}
                                className={`${controlClassName} mt-1.5 h-11 text-base`}
                            />
                            {errors.client_name && <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.client_name}</p>}
                        </div>

                        <div>
                            <label htmlFor="client_contact" className="block text-sm font-medium text-slate-900 dark:text-slate-100">
                                {t('serviceFeedback.clientContact')}
                                <span className="ml-1 font-normal text-slate-400">({t('serviceFeedback.clientContactHint')})</span>
                            </label>
                            <input
                                id="client_contact"
                                type="text"
                                maxLength={120}
                                value={data.client_contact}
                                onChange={(e) => setData('client_contact', e.target.value)}
                                className={`${controlClassName} mt-1.5 h-11 text-base`}
                            />
                            {errors.client_contact && <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.client_contact}</p>}
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className={`${publicButtonPrimary} min-h-[48px] w-full text-base`}
                    >
                        {processing ? t('serviceFeedback.submitting') : t('serviceFeedback.submitFeedback')}
                    </button>

                    <p className="text-center text-xs text-slate-500 dark:text-slate-400">
                        {t('serviceFeedback.publicPrivacyNote')}
                    </p>
                </form>
            </div>
        </PublicLayout>
    );
}
