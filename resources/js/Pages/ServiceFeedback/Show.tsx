import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX, ReactNode } from 'react';

type NamePair = { en: string | null; am: string | null } | null;

type Feedback = {
    id: string;
    rating: number;
    comment: string | null;
    status: string;
    client_name: string | null;
    client_contact: string | null;
    created_at: string | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
    review_note: string | null;
    employee: { id: string; name: string | null; employee_number: string | null } | null;
    organization: NamePair;
    organization_unit: NamePair;
    position: NamePair;
    service_type: NamePair;
};

type Props = {
    feedback: Feedback;
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
};

export default function ServiceFeedbackShow({ feedback, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const label = (pair: NamePair): string => (am ? (pair?.am ?? pair?.en) : pair?.en) ?? '—';

    const { data, setData, post, processing } = useForm({
        status: 'reviewed',
        review_note: feedback.review_note ?? '',
    });

    const isHidden = feedback.status === 'hidden';

    function submitReview(status: 'reviewed' | 'resolved') {
        // setData is async; pass the value through the request explicitly so
        // the intended status is sent even on the first click.
        router.post(
            route('service-feedback.admin.review', feedback.id),
            { status, review_note: data.review_note },
            { preserveScroll: true },
        );
    }

    function toggleHide() {
        router.post(route('service-feedback.admin.hide', feedback.id), {}, { preserveScroll: true });
    }

    function destroy() {
        if (!window.confirm(t('confirmations.deleteWarning'))) {
            return;
        }

        router.delete(route('service-feedback.admin.destroy', feedback.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.feedbackDetail')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.feedbackDetail')}
                    backHref={route('service-feedback.admin.index')}
                    actions={<StatusBadge status={feedback.status} />}
                />

                <div className="grid gap-5 lg:grid-cols-3">
                    {/* Rating + comment */}
                    <div className="space-y-5 lg:col-span-2">
                        <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="flex items-center gap-3">
                                <RatingStars rating={feedback.rating} size="md" showValue />
                                <span className="text-sm text-gray-500 dark:text-slate-400">
                                    {t(`serviceFeedback.rating${feedback.rating}`)}
                                </span>
                            </div>

                            <div className="mt-4">
                                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                    {t('serviceFeedback.comment')}
                                </div>
                                <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-slate-200">
                                    {feedback.comment ?? '—'}
                                </p>
                            </div>
                        </div>

                        {/* Moderation */}
                        {(can.review || can.hide || can.delete) && (
                            <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                    {t('serviceFeedback.reviewNote')}
                                </h2>

                                {can.review && (
                                    <>
                                        <textarea
                                            rows={3}
                                            maxLength={2000}
                                            value={data.review_note}
                                            onChange={(e) => setData('review_note', e.target.value)}
                                            className="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                        />

                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                disabled={processing}
                                                onClick={() => submitReview('reviewed')}
                                                className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                                            >
                                                {t('serviceFeedback.markReviewed')}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={processing}
                                                onClick={() => submitReview('resolved')}
                                                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                            >
                                                {t('serviceFeedback.markResolved')}
                                            </button>
                                        </div>
                                    </>
                                )}

                                <div className="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3 dark:border-slate-800">
                                    {can.hide && (
                                        <button
                                            type="button"
                                            onClick={toggleHide}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            {isHidden ? t('serviceFeedback.unhideFeedback') : t('serviceFeedback.hideFeedback')}
                                        </button>
                                    )}
                                    {can.delete && (
                                        <button
                                            type="button"
                                            onClick={destroy}
                                            className="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                        >
                                            {t('serviceFeedback.deleteFeedback')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Context */}
                    <div className="space-y-5">
                        <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <dl className="space-y-3 text-sm">
                                <Row label={t('serviceFeedback.filterEmployee')}>
                                    <div className="text-gray-900 dark:text-slate-100">{feedback.employee?.name ?? '—'}</div>
                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                        {feedback.employee?.employee_number}
                                    </div>
                                </Row>
                                <Row label={t('serviceFeedback.filterServiceType')}>{label(feedback.service_type)}</Row>
                                <Row label={t('serviceFeedback.filterOrganization')}>{label(feedback.organization)}</Row>
                                <Row label={t('serviceFeedback.filterUnit')}>{label(feedback.organization_unit)}</Row>
                                <Row label={t('serviceFeedback.servicePosition')}>{label(feedback.position)}</Row>
                                <Row label={t('serviceFeedback.submittedDate')}>
                                    <LocalizedDateDisplay value={feedback.created_at} withTime />
                                </Row>
                            </dl>
                        </div>

                        {/* Volunteered client details; absent for anonymous submissions. */}
                        <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <dl className="space-y-3 text-sm">
                                <Row label={t('serviceFeedback.clientName')}>
                                    {feedback.client_name ?? t('serviceFeedback.anonymousClient')}
                                </Row>
                                <Row label={t('serviceFeedback.clientContact')}>{feedback.client_contact ?? '—'}</Row>
                            </dl>
                        </div>

                        {feedback.reviewed_at && (
                            <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <dl className="space-y-3 text-sm">
                                    <Row label={t('serviceFeedback.reviewedBy')}>{feedback.reviewed_by ?? '—'}</Row>
                                    <Row label={t('serviceFeedback.statusReviewed')}>
                                        <LocalizedDateDisplay value={feedback.reviewed_at} withTime />
                                    </Row>
                                    {feedback.review_note && (
                                        <Row label={t('serviceFeedback.reviewNote')}>{feedback.review_note}</Row>
                                    )}
                                </dl>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-0.5 text-gray-800 dark:text-slate-200">{children}</dd>
        </div>
    );
}
