import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { buttonClassName, cx, inputClassName, textareaClassName } from '@/Components/ui';
type Approval = { id: string; approval_type: string; status: string; rejection_reason: string | null; decided_at: string | null; approver: { name: string } | null };
type Review = { id: string; action: string; notes: string | null; created_at: string; reviewer: { name: string } | null };
type Document = { id: string; document_type: string; original_name: string; file_type: string; verification_status: string };

type Application = {
    id: string;
    status: string;
    submitted_at: string | null;
    effective_date: string | null;
    approved_at: string | null;
    implementation_failure: string | null;
    applicant_notes: string | null;
    rejected_reason: string | null;
    employee: { id: string; employee_number: string; full_name: string } | null;
    announcement: {
        id: string;
        organization: { name_en: string; name_am: string | null } | null;
        position: { title_en: string; title_am: string | null } | null;
    } | null;
    releasingOrganization: { name_en: string; name_am: string | null } | null;
    receivingOrganization: { name_en: string; name_am: string | null } | null;
    documents: Document[];
    approvals: Approval[];
    screening_reviews: Review[];
};

type Can = {
    screen: boolean;
    select: boolean;
    reject: boolean;
    withdraw: boolean;
    approveRelease: boolean;
    approveReceiving: boolean;
    approveFinal: boolean;
    complete: boolean;
};

type Props = { application: Application; can: Can };

export default function TransferApplicationShow({ application, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const useAmharic = locale === 'am';

    // The app's own dialog asks for the reason; a browser prompt() is unstyled,
    // untranslatable in its chrome and cannot show a validation message.
    async function withdraw() {
        const { confirmed, reason } = await confirm({
            title: t('transfers.withdrawApplication'),
            description: t('transfers.withdrawReasonPrompt'),
            confirmLabel: t('transfers.withdrawApplication'),
            cancelLabel: t('common.cancel'),
            variant: 'warning',
            requireReason: true,
            reasonLabel: t('confirmations.reason'),
        });
        if (confirmed && reason?.trim()) {
            router.post(route('transfer-applications.withdraw', application.id), { reason: reason.trim() }, { preserveScroll: true });
        }
    }

    const [rejectOpen, setRejectOpen] = useState(false);
    const rejectForm = useForm({ reason: '' });
    const [effectiveDate, setEffectiveDate] = useState('');

    const APPROVAL_TYPE_LABELS: Record<string, string> = {
        release:   t('transfers.approvalTypeRelease'),
        receiving: t('transfers.approvalTypeReceiving'),
        final:     t('transfers.approvalTypeFinal'),
    };

    const SCREEN_ACTION_LABELS: Record<string, string> = {
        submitted:   t('transfers.screenActionSubmitted'),
        under_review: t('transfers.screenActionUnderReview'),
        verified:    t('transfers.screenActionVerified'),
        selected:    t('transfers.screenActionSelected'),
        rejected:    t('transfers.screenActionRejected'),
    };

    function orgName(org: { name_en: string; name_am: string | null } | null) {
        return (useAmharic ? org?.name_am : null) ?? org?.name_en ?? '-';
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfer-applications.index')}
                    title={application.employee?.full_name ?? t('transfers.application')}
                    description={application.employee?.employee_number}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.screen && application.status === 'submitted' && (
                                <button type="button" onClick={() => router.post(route('transfer-applications.screen', application.id))} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t('transfers.screen')}
                                </button>
                            )}
                            {can.select && ['under_review', 'verified'].includes(application.status) && (
                                <div className="flex items-center gap-2">
                                    <input
                                        aria-label={t('transfers.effectiveDate')}
                                        className={inputClassName}
                                        min={new Date().toISOString().slice(0, 10)}
                                        type="date"
                                        value={effectiveDate}
                                        onChange={(event) => setEffectiveDate(event.target.value)}
                                    />
                                    <button type="button" onClick={() => router.post(route('transfer-applications.select', application.id), effectiveDate ? { effective_date: effectiveDate } : {})} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                        {t('transfers.select')}
                                    </button>
                                </div>
                            )}
                            {can.reject && !['rejected', 'withdrawn', 'cancelled', 'transferred'].includes(application.status) && (
                                <button type="button" onClick={() => setRejectOpen(true)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t('transfers.reject')}
                                </button>
                            )}
                            {can.withdraw && (
                                <button type="button" onClick={withdraw} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t('transfers.withdrawApplication')}
                                </button>
                            )}
                            {can.approveRelease && application.status === 'release_pending' && (
                                <button type="button" onClick={() => router.post(route('transfer-applications.approve-release', application.id))} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                    {t('transfers.approveRelease')}
                                </button>
                            )}
                            {can.approveReceiving && application.status === 'receiving_pending' && (
                                <button type="button" onClick={() => router.post(route('transfer-applications.approve-receiving', application.id))} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                    {t('transfers.approveReceiving')}
                                </button>
                            )}
                            {can.approveFinal && application.status === 'final_approval_pending' && (
                                <button type="button" onClick={() => router.post(route('transfer-applications.approve-final', application.id))} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                    {t('transfers.approveFinal')}
                                </button>
                            )}
                            {can.complete && ['approved', 'implementation_failed'].includes(application.status) && (
                                <button type="button" onClick={() => router.post(route('transfer-applications.complete', application.id))} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                    {t('transfers.implementTransfer')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={application.employee?.full_name ?? t('transfers.application')} />

            {rejectOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                    <div className="w-full max-w-md rounded-card bg-(--app-surface) p-6 shadow-xl">
                        <h3 className="mb-3 text-base font-semibold text-(--app-foreground)">{t('transfers.reject')}</h3>
                        <textarea
                            rows={4}
                            className={cx(textareaClassName, 'w-full')}
                            placeholder={t('transfers.rejectedReason')}
                            value={rejectForm.data.reason}
                            onChange={(e) => rejectForm.setData('reason', e.target.value)}
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setRejectOpen(false)} className={buttonClassName({ variant: 'outline' })}>
                                {t('common.cancel')}
                            </button>
                            <button
                                type="button"
                                disabled={!rejectForm.data.reason.trim()}
                                onClick={() => rejectForm.post(route('transfer-applications.reject', application.id), { onSuccess: () => setRejectOpen(false) })}
                                className={buttonClassName({ variant: 'destructive' })}
                            >
                                {t('transfers.reject')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                {/* Left: details */}
                <div className="space-y-6">
                    <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                        <div className="flex items-center justify-between">
                            <h3 className="font-semibold text-(--app-foreground)">
                                {(useAmharic ? application.announcement?.position?.title_am : null) ?? application.announcement?.position?.title_en ?? '-'}
                            </h3>
                            <StatusBadge status={application.status} />
                        </div>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt className="text-xs font-medium text-(--app-muted-foreground)">{t('transfers.releasingOrganization')}</dt><dd className="mt-1 text-(--app-foreground)">{orgName(application.releasingOrganization)}</dd></div>
                            <div><dt className="text-xs font-medium text-(--app-muted-foreground)">{t('transfers.receivingOrganization')}</dt><dd className="mt-1 text-(--app-foreground)">{orgName(application.receivingOrganization)}</dd></div>
                            <div><dt className="text-xs font-medium text-(--app-muted-foreground)">{t('common.submittedAt')}</dt><dd className="mt-1 text-(--app-foreground)"><LocalizedDateDisplay value={application.submitted_at} fallback="-" /></dd></div>
                        </dl>

                        {application.applicant_notes && (
                            <div className="mt-4">
                                <p className="text-xs font-medium text-(--app-muted-foreground)">{t('transfers.applicantNotes')}</p>
                                <p className="mt-1 whitespace-pre-line text-sm text-(--app-foreground)">{application.applicant_notes}</p>
                            </div>
                        )}
                        {application.rejected_reason && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30">
                                <p className="text-xs font-medium text-red-700 dark:text-red-400">{t('transfers.rejectedReason')}</p>
                                <p className="mt-1 text-sm text-red-800 dark:text-red-300">{application.rejected_reason}</p>
                            </div>
                        )}
                    </section>

                    {/* Approval chain */}
                    {application.approvals.length > 0 && (
                        <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                            <h4 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('transfers.approvalChain')}</h4>
                            <div className="space-y-2">
                                {application.approvals.map((approval) => (
                                    <div key={approval.id} className="flex items-center justify-between rounded-lg border border-(--app-border) px-4 py-3">
                                        <div>
                                            <p className="text-sm font-medium text-(--app-foreground)">{APPROVAL_TYPE_LABELS[approval.approval_type] ?? approval.approval_type}</p>
                                            {approval.approver && <p className="text-xs text-(--app-muted-foreground)">{approval.approver.name} · <LocalizedDateDisplay value={approval.decided_at} /></p>}
                                        </div>
                                        <StatusBadge status={approval.status} />
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                </div>

                {/* Right: review history */}
                <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                    <h4 className="mb-3 font-semibold text-(--app-foreground)">{t('transfers.reviewHistory')}</h4>
                    {application.screening_reviews.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{t('common.noResults')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {application.screening_reviews.map((review) => (
                                <li key={review.id} className="rounded-lg border border-(--app-border) p-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold uppercase text-(--app-muted-foreground)">{SCREEN_ACTION_LABELS[review.action] ?? review.action}</span>
                                        <LocalizedDateDisplay value={review.created_at} className="text-xs text-(--app-muted-foreground)" />
                                    </div>
                                    {review.reviewer && <p className="mt-1 text-xs text-(--app-muted-foreground)">{review.reviewer.name}</p>}
                                    {review.notes && <p className="mt-1 text-sm text-(--app-foreground)">{review.notes}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
