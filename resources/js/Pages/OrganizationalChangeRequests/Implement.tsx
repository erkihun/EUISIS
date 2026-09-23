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
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { AlertTriangle, InfoIcon } from '@/Components/Icons';
import { useState, type JSX } from 'react';
import type {
    ChangeRequestDetail,
    Conflict,
    ImpactPayload,
} from '@/Components/organizationalChange/types';

type Props = {
    request: ChangeRequestDetail;
    impact: ImpactPayload | null;
    conflicts: Conflict[];
    can: {
        implement: boolean;
        complete: boolean;
        returnForAmendment: boolean;
        assignImplementation: boolean;
    };
};

const primaryBtn =
    'rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-50';
const secondaryBtn =
    'rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/**
 * The implementation workspace.
 *
 * Everything on this page is read-only except the implementation note. The
 * approved quantity, grade, unit, parent, title and effective date are shown
 * as they were signed off and cannot be edited: if any of them is wrong, the
 * only route forward is Return for Amendment, which sends the request back to
 * the requester for a fresh approval round.
 */
export default function OrganizationalChangeRequestsImplement({ request, impact, conflicts, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const [showAmendment, setShowAmendment] = useState(false);

    const implementForm = useForm({ note: '' });
    const amendmentForm = useForm({ comment: '' });

    const blocked = conflicts.length > 0 || request.status === 'implementation_blocked';
    const alreadyApplied = request.status === 'implemented' || request.status === 'completed';

    function apply() {
        implementForm.post(route('organizational-change-requests.implement', request.id), { preserveScroll: true });
    }

    function complete() {
        implementForm.post(route('organizational-change-requests.complete', request.id), { preserveScroll: true });
    }

    function returnForAmendment() {
        amendmentForm.post(route('organizational-change-requests.return-for-amendment', request.id), {
            preserveScroll: true,
            onSuccess: () => {
                amendmentForm.reset();
                setShowAmendment(false);
            },
        });
    }

    const organizationName = request.organization
        ? (am ? request.organization.name_am || request.organization.name_en : request.organization.name_en)
        : '—';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={`${t('organizationalChangeRequests.implementation.heading')} — ${request.request_no}`}
                    description={t(`organizationalChangeRequests.types.${request.request_type}`)}
                />
            }
        >
            <Head title={`${request.request_no} — ${t('organizationalChangeRequests.implementation.heading')}`} />

            <div className="space-y-4">
                <section className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center gap-4">
                        <StatusBadge status={request.status} label={t(`organizationalChangeRequests.statuses.${request.status}`)} />
                        <span className="text-xs text-gray-500 dark:text-slate-400">
                            {organizationName}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-slate-400">
                            {t('organizationalChangeRequests.implementation.approvedBy')}: {request.approver?.name ?? '—'}
                            {request.approved_at ? ' · ' : ''}
                            {request.approved_at ? <LocalizedDateDisplay value={request.approved_at} withTime /> : null}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-slate-400">
                            {t('organizationalChangeRequests.fields.effectiveDate')}:{' '}
                            {request.requested_effective_date
                                ? <LocalizedDateDisplay value={request.requested_effective_date} />
                                : '—'}
                        </span>
                    </div>
                </section>

                {/* Read-only notice */}
                <section className="flex items-start gap-2.5 rounded-panel border border-amber-200 bg-amber-50/50 px-4 py-3 dark:border-amber-900/40 dark:bg-amber-950/20">
                    <InfoIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-400" aria-hidden="true" />
                    <p className="text-sm text-amber-900 dark:text-amber-200">
                        {t('organizationalChangeRequests.implementation.readOnlyNotice')}
                    </p>
                </section>

                <ConflictPanel conflicts={conflicts} showEmptyState={!alreadyApplied} />

                {request.status === 'implementation_blocked' && (
                    <section className="flex items-start gap-2.5 rounded-panel border border-red-200 bg-red-50/50 px-4 py-3 dark:border-red-900/40 dark:bg-red-950/20">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-700 dark:text-red-400" aria-hidden="true" />
                        <p className="text-sm text-red-900 dark:text-red-200">
                            {t('organizationalChangeRequests.implementation.blockedNotice')}
                        </p>
                    </section>
                )}

                {/* Approved values — read only */}
                <BeforeAfterPanel item={request.items?.[0]} />
                <ImpactPanel impact={impact} />

                {/* Implementation result, once applied */}
                {request.implementation_result && (
                    <section className="rounded-panel border border-emerald-200 bg-emerald-50/40 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <h2 className="text-sm font-semibold text-emerald-900 dark:text-emerald-300">
                            {t('organizationalChangeRequests.implementation.resultHeading')}
                        </h2>
                        <pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all text-xs text-emerald-900 dark:text-emerald-200">
                            {JSON.stringify(request.implementation_result, null, 2)}
                        </pre>
                    </section>
                )}

                {/* Actions */}
                {(can.implement || can.complete || can.returnForAmendment) && (
                    <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        {can.implement && !alreadyApplied && (
                            <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                                <span>{t('organizationalChangeRequests.fields.note')}</span>
                                <textarea
                                    rows={2}
                                    className={inputCls}
                                    value={implementForm.data.note}
                                    onChange={event => implementForm.setData('note', event.target.value)}
                                />
                            </label>
                        )}

                        <div className="mt-3 flex flex-wrap justify-end gap-2">
                            {can.returnForAmendment && (
                                <button type="button" className={secondaryBtn} onClick={() => setShowAmendment(value => !value)}>
                                    {t('organizationalChangeRequests.actions.returnForAmendment')}
                                </button>
                            )}
                            {can.complete && (
                                <button type="button" className={primaryBtn} disabled={implementForm.processing} onClick={complete}>
                                    {t('organizationalChangeRequests.actions.complete')}
                                </button>
                            )}
                            {can.implement && !alreadyApplied && (
                                <button
                                    type="button"
                                    className={primaryBtn}
                                    disabled={implementForm.processing || blocked}
                                    onClick={apply}
                                >
                                    {t('organizationalChangeRequests.actions.applyApprovedChange')}
                                </button>
                            )}
                        </div>

                        {showAmendment && (
                            <div className="mt-4 border-t border-gray-100 pt-4 dark:border-slate-800">
                                <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                                    <span>{t('organizationalChangeRequests.fields.comment')}</span>
                                    <textarea
                                        rows={3}
                                        className={inputCls}
                                        value={amendmentForm.data.comment}
                                        onChange={event => amendmentForm.setData('comment', event.target.value)}
                                    />
                                </label>
                                {amendmentForm.errors.comment && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{amendmentForm.errors.comment}</p>
                                )}
                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        className={primaryBtn}
                                        disabled={amendmentForm.processing}
                                        onClick={returnForAmendment}
                                    >
                                        {t('common.confirm')}
                                    </button>
                                </div>
                            </div>
                        )}
                    </section>
                )}

                <RequestTimeline request={request} />
                <ReviewHistoryPanel reviews={request.reviews ?? []} history={request.history ?? []} />
                <AttachmentsPanel request={request} canUpload={false} attachmentTypes={[]} />
            </div>
        </AuthenticatedLayout>
    );
}
