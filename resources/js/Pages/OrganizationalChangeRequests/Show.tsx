import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import BeforeAfterPanel from '@/Components/organizationalChange/BeforeAfterPanel';
import ImpactPanel from '@/Components/organizationalChange/ImpactPanel';
import ConflictPanel from '@/Components/organizationalChange/ConflictPanel';
import RequestTimeline from '@/Components/organizationalChange/RequestTimeline';
import AttachmentsPanel from '@/Components/organizationalChange/AttachmentsPanel';
import ReviewHistoryPanel from '@/Components/organizationalChange/ReviewHistoryPanel';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { InfoIcon } from '@/Components/Icons';
import { useState, type JSX } from 'react';
import type {
    ChangeRequestAbilities,
    ChangeRequestDetail,
    Conflict,
    ImpactPayload,
} from '@/Components/organizationalChange/types';

type Props = {
    request: ChangeRequestDetail;
    impact: ImpactPayload | null;
    conflicts: Conflict[];
    can: ChangeRequestAbilities;
};

const primaryBtn =
    'rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50';
const secondaryBtn =
    'rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
const dangerBtn =
    'rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30';
const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/**
 * Request detail.
 *
 * The action bar is driven entirely by server-supplied abilities, and every
 * button posts to an endpoint that re-authorises. Hiding a button here is a
 * convenience, never the control.
 */
export default function OrganizationalChangeRequestsShow({ request, impact, conflicts, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const [openAction, setOpenAction] = useState<string | null>(null);

    const commentForm = useForm({ comment: '' });

    function post(routeName: string, requiresComment = false) {
        if (requiresComment && commentForm.data.comment.trim() === '') {
            setOpenAction(routeName);
            return;
        }

        commentForm.post(route(routeName, request.id), {
            preserveScroll: true,
            onSuccess: () => {
                commentForm.reset('comment');
                setOpenAction(null);
            },
        });
    }

    const organizationName = request.organization
        ? (am ? request.organization.name_am || request.organization.name_en : request.organization.name_en)
        : '—';

    const metaRows: { label: string; value: JSX.Element | string }[] = [
        { label: t('organizationalChangeRequests.fields.organization'), value: organizationName },
        { label: t('organizationalChangeRequests.fields.requestType'), value: t(`organizationalChangeRequests.types.${request.request_type}`) },
        { label: t('organizationalChangeRequests.fields.requester'), value: request.requester?.name ?? '—' },
        { label: t('organizationalChangeRequests.fields.priority'), value: t(`organizationalChangeRequests.priorities.${request.priority}`) },
        {
            label: t('organizationalChangeRequests.fields.effectiveDate'),
            value: request.requested_effective_date
                ? <LocalizedDateDisplay value={request.requested_effective_date} />
                : '—',
        },
        { label: t('organizationalChangeRequests.fields.implementingUnit'), value: request.implementing_unit?.name_en ?? request.implementing_unit_key ?? '—' },
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={request.request_no}
                    description={t(`organizationalChangeRequests.types.${request.request_type}`)}
                    actions={
                        can.update ? (
                            <Link href={route('organizational-change-requests.edit', request.id)} className={secondaryBtn}>
                                {t('common.edit')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={request.request_no} />

            <div className="space-y-4">
                {/* Status strip */}
                <section className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusBadge
                            status={request.status}
                            label={t(`organizationalChangeRequests.statuses.${request.status}`)}
                        />
                        <span className="text-xs text-gray-500 dark:text-slate-400">
                            {t('organizationalChangeRequests.fields.submittedAt')}:{' '}
                            {request.submitted_at ? <LocalizedDateDisplay value={request.submitted_at} withTime /> : '—'}
                        </span>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {can.submit && (
                            <button type="button" className={primaryBtn} onClick={() => post('organizational-change-requests.submit')}>
                                {t('organizationalChangeRequests.actions.submit')}
                            </button>
                        )}
                        {can.resubmit && (
                            <button type="button" className={primaryBtn} onClick={() => post('organizational-change-requests.resubmit')}>
                                {t('organizationalChangeRequests.actions.resubmit')}
                            </button>
                        )}
                        {can.review && (
                            <button type="button" className={secondaryBtn} onClick={() => post('organizational-change-requests.start-review')}>
                                {t('organizationalChangeRequests.actions.startReview')}
                            </button>
                        )}
                        {can.requestCorrection && (
                            <button
                                type="button"
                                className={secondaryBtn}
                                onClick={() => setOpenAction('organizational-change-requests.request-correction')}
                            >
                                {t('organizationalChangeRequests.actions.requestCorrection')}
                            </button>
                        )}
                        {can.approve && (
                            <button type="button" className={primaryBtn} onClick={() => post('organizational-change-requests.approve')}>
                                {t('organizationalChangeRequests.actions.approve')}
                            </button>
                        )}
                        {can.reject && (
                            <button
                                type="button"
                                className={dangerBtn}
                                onClick={() => setOpenAction('organizational-change-requests.reject')}
                            >
                                {t('organizationalChangeRequests.actions.reject')}
                            </button>
                        )}
                        {(can.implement || can.complete || can.returnForAmendment) && (
                            <Link href={route('organizational-change-requests.implementation', request.id)} className={primaryBtn}>
                                {t('organizationalChangeRequests.actions.openImplementation')}
                            </Link>
                        )}
                        {can.cancel && (
                            <button
                                type="button"
                                className={dangerBtn}
                                onClick={() => setOpenAction('organizational-change-requests.cancel')}
                            >
                                {t('organizationalChangeRequests.actions.cancel')}
                            </button>
                        )}
                    </div>
                </section>

                {/* Comment prompt for actions that need one */}
                {openAction && (
                    <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                            <span>{t('organizationalChangeRequests.fields.comment')}</span>
                            <textarea
                                rows={3}
                                className={inputCls}
                                value={commentForm.data.comment}
                                onChange={event => commentForm.setData('comment', event.target.value)}
                            />
                        </label>
                        {commentForm.errors.comment && (
                            <p className="mt-1 text-xs text-red-600 dark:text-red-400">{commentForm.errors.comment}</p>
                        )}
                        <div className="mt-3 flex justify-end gap-2">
                            <button type="button" className={secondaryBtn} onClick={() => setOpenAction(null)}>
                                {t('common.cancel')}
                            </button>
                            <button
                                type="button"
                                className={primaryBtn}
                                disabled={commentForm.processing}
                                onClick={() => post(openAction)}
                            >
                                {t('common.confirm')}
                            </button>
                        </div>
                    </section>
                )}

                {/* Approval is not implementation */}
                {request.status === 'pending_implementation' && (
                    <section className="flex items-start gap-2.5 rounded-panel border border-blue-200 bg-blue-50/50 px-4 py-3 dark:border-blue-900/40 dark:bg-blue-950/20">
                        <InfoIcon className="mt-0.5 h-4 w-4 shrink-0 text-blue-700 dark:text-blue-400" aria-hidden="true" />
                        <p className="text-sm text-blue-900 dark:text-blue-200">
                            {t('organizationalChangeRequests.notice.approvalIsNotImplementation')}
                        </p>
                    </section>
                )}

                <ConflictPanel conflicts={conflicts} />

                {/* Metadata */}
                <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <dl className="grid grid-cols-1 gap-px bg-gray-100 sm:grid-cols-2 lg:grid-cols-3 dark:bg-slate-800">
                        {metaRows.map(row => (
                            <div key={row.label} className="bg-white p-3 dark:bg-slate-900">
                                <dt className="text-xs text-gray-500 dark:text-slate-400">{row.label}</dt>
                                <dd className="mt-1 text-sm font-medium text-gray-900 dark:text-slate-100">{row.value}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                        <p className="text-xs text-gray-500 dark:text-slate-400">
                            {t('organizationalChangeRequests.fields.reason')}
                        </p>
                        <p className="mt-1 whitespace-pre-line text-sm text-gray-800 dark:text-slate-200">{request.reason}</p>
                    </div>
                </section>

                <BeforeAfterPanel item={request.items?.[0]} />
                <ImpactPanel impact={impact} />
                <RequestTimeline request={request} />
                <ReviewHistoryPanel reviews={request.reviews ?? []} history={request.history ?? []} />
                <AttachmentsPanel
                    request={request}
                    canUpload={Boolean(can.uploadAttachment)}
                    attachmentTypes={['approved_structure', 'decision_letter', 'study_document', 'organogram', 'supporting_evidence', 'other']}
                />
            </div>
        </AuthenticatedLayout>
    );
}
