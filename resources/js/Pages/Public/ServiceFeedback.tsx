import { FormEvent, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
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

export default function ServiceFeedback({ available, token, context, serviceTypes, submitted }: Props) {
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
        post(`/service-feedback/${token}`, {
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
            <PublicLayout title={t('serviceFeedback.publicTitle')}>
                <Head title={t('serviceFeedback.publicTitle')} />
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <h1 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                            {t('serviceFeedback.linkUnavailable')}
                        </h1>
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
            <PublicLayout title={t('serviceFeedback.publicTitle')}>
                <Head title={t('serviceFeedback.publicTitle')} />
                <div className="mx-auto max-w-md px-4 py-12">
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900">
                            <svg viewBox="0 0 24 24" className="h-6 w-6 text-emerald-700 dark:text-emerald-300" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                <path d="M5 13l4 4L19 7" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        </div>
                        <h1 className="mt-4 text-lg font-semibold text-emerald-900 dark:text-emerald-100">
                            {t('serviceFeedback.feedbackSubmitted')}
                        </h1>
                        <p className="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                            {t('serviceFeedback.feedbackSubmittedDetail')}
                        </p>
                    </div>
                </div>
            </PublicLayout>
        );
    }

    const activeRating = hoveredRating ?? data.rating;

    return (
        <PublicLayout title={t('serviceFeedback.publicTitle')}>
            <Head title={t('serviceFeedback.publicTitle')} />

            <div className="mx-auto max-w-md px-4 py-8">
                {/* Service point context — office and role only, never a person. */}
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <h1 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {context?.display_name}
                    </h1>
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

                <form onSubmit={submit} className="mt-4 space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
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
                            className="mt-1.5 block w-full rounded-lg border-slate-300 py-2.5 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                        >
                            <option value="">{t('serviceFeedback.serviceTypePlaceholder')}</option>
                            {serviceTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.service_no ? `${type.service_no} — ${type.name}` : type.name}
                                </option>
                            ))}
                        </select>
                        {errors.position_service_id && (
                            <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.position_service_id}</p>
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
                                    className="rounded-md p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                >
                                    <StarIcon filled={value <= activeRating} />
                                </button>
                            ))}
                        </div>
                        <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                            {data.rating > 0 ? t(`serviceFeedback.rating${data.rating}`) : t('serviceFeedback.ratingHint')}
                        </p>
                        {errors.rating && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.rating}</p>}
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
                            className="mt-1.5 block w-full rounded-lg border-slate-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                        />
                        {errors.comment && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.comment}</p>}
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
                                className="mt-1.5 block w-full rounded-lg border-slate-300 py-2.5 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                            />
                            {errors.client_name && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.client_name}</p>}
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
                                className="mt-1.5 block w-full rounded-lg border-slate-300 py-2.5 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                            />
                            {errors.client_contact && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.client_contact}</p>}
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg bg-indigo-600 px-4 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
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
