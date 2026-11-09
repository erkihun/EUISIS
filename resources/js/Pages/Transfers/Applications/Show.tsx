import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import TransferProgress from '@/Components/transfers/TransferProgress';
import TransferDocumentList from '@/Components/transfers/TransferDocumentList';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { AppDialog, Alert, Button, Card, CardHeader, CardTitle, FormField, Textarea } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { APPROVAL_STATUS, type ApprovalType, type TransferApplicationRecord } from '@/Components/transfers/types';

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

type Props = { application: TransferApplicationRecord; can: Can };

const FINAL_STATUSES = ['transferred', 'rejected', 'withdrawn', 'cancelled'];

/** Route names per approval stage: approve, reject, and the permission flag that unlocks both. */
const STAGE_ROUTES: Record<ApprovalType, { approve: string; reject: string; can: keyof Can }> = {
    release: { approve: 'transfer-applications.approve-release', reject: 'transfer-applications.reject-release', can: 'approveRelease' },
    receiving: { approve: 'transfer-applications.approve-receiving', reject: 'transfer-applications.reject-receiving', can: 'approveReceiving' },
    final: { approve: 'transfer-applications.approve-final', reject: 'transfer-applications.reject-final', can: 'approveFinal' },
};

export default function TransferApplicationShow({ application, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const { organizationName } = useDisplayFormat();
    const [busy, setBusy] = useState(false);
    const [selectOpen, setSelectOpen] = useState(false);

    const status = application.status;
    const approvals = application.approvals ?? [];
    const positionTitle = application.position
        ? `${application.position.code ? `${application.position.code} · ` : ''}${localizedName(application.position.title_en, application.position.title_am, locale)}`
        : '—';
    const from = application.releasing_organization ? organizationName(application.releasing_organization.name_en, application.releasing_organization.name_am) : '—';
    const to = application.receiving_organization ? organizationName(application.receiving_organization.name_en, application.receiving_organization.name_am) : '—';
    const pendingStage = (Object.keys(APPROVAL_STATUS) as ApprovalType[]).find((type) => APPROVAL_STATUS[type] === status);

    /** Posts a workflow step once; the page reloads with the outcome toast. */
    function post(routeName: string, data: Record<string, string> = {}) {
        router.post(route(routeName, application.id), data, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    }

    async function startReview() {
        const { confirmed } = await confirm({
            title: t('transfers.screen'),
            description: t('transfers.confirmStartReview'),
            confirmLabel: t('transfers.screen'),
            cancelLabel: t('common.cancel'),
        });
        if (confirmed) post('transfer-applications.screen');
    }

    async function approveStage(type: ApprovalType) {
        const { confirmed } = await confirm({
            title: t(`transfers.approvalType${capitalize(type)}`),
            description: t(`transfers.confirmApprove${capitalize(type)}`).replaceAll(':from', from).replaceAll(':to', to),
            confirmLabel: t('transfers.approve'),
            cancelLabel: t('common.cancel'),
            variant: 'success',
        });
        if (confirmed) post(STAGE_ROUTES[type].approve);
    }

    async function rejectWithReason(routeName: string, title: string, description: string) {
        const { confirmed, reason } = await confirm({
            title,
            description,
            confirmLabel: t('transfers.reject'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
            requireReason: true,
            reasonLabel: t('transfers.rejectedReason'),
        });
        if (confirmed && reason?.trim()) post(routeName, { reason: reason.trim() });
    }

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
        if (confirmed && reason?.trim()) post('transfer-applications.withdraw', { reason: reason.trim() });
    }

    async function implement() {
        const { confirmed } = await confirm({
            title: t('transfers.implementTransfer'),
            description: t('transfers.confirmImplement').replaceAll(':from', from).replaceAll(':to', to),
            confirmLabel: t('transfers.implementTransfer'),
            cancelLabel: t('common.cancel'),
            variant: 'warning',
        });
        if (confirmed) post('transfer-applications.complete');
    }

    // One next step leads; refusals sit beside it as outline buttons.
    const actions: ReactNode[] = [];
    if (can.withdraw && ['submitted', 'under_review', 'verified'].includes(status)) {
        actions.push(<Button key="withdraw" variant="outline" disabled={busy} onClick={withdraw}>{t('transfers.withdrawApplication')}</Button>);
    }
    if (can.reject && !FINAL_STATUSES.includes(status) && !pendingStage) {
        actions.push(
            <Button key="reject" variant="destructiveOutline" disabled={busy} onClick={() => rejectWithReason('transfer-applications.reject', t('transfers.rejectApplication'), t('transfers.rejectApplicationHint'))}>
                {t('transfers.rejectApplication')}
            </Button>,
        );
    }
    if (pendingStage && can[STAGE_ROUTES[pendingStage].can]) {
        actions.push(
            <Button key="reject-stage" variant="destructiveOutline" disabled={busy} onClick={() => rejectWithReason(STAGE_ROUTES[pendingStage].reject, t(`transfers.reject${capitalize(pendingStage)}`), t('transfers.rejectStageHint'))}>
                {t(`transfers.reject${capitalize(pendingStage)}`)}
            </Button>,
            <Button key="approve-stage" variant="primary" loading={busy} onClick={() => approveStage(pendingStage)}>
                {t(`transfers.approve${capitalize(pendingStage)}`)}
            </Button>,
        );
    }
    if (can.screen && status === 'submitted') {
        actions.push(<Button key="screen" variant="primary" loading={busy} onClick={startReview}>{t('transfers.screen')}</Button>);
    }
    if (can.select && ['under_review', 'verified'].includes(status)) {
        actions.push(<Button key="select" variant="primary" disabled={busy} onClick={() => setSelectOpen(true)}>{t('transfers.select')}</Button>);
    }
    if (can.complete && ['approved', 'implementation_failed'].includes(status)) {
        actions.push(<Button key="complete" variant="primary" loading={busy} onClick={implement}>{t('transfers.implementTransfer')}</Button>);
    }

    const name = application.employee?.full_name ?? t('transfers.application');
    const description = [application.employee?.employee_number, application.application_number].filter(Boolean).join(' · ');

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfer-applications.index')}
                    title={name}
                    description={description || undefined}
                    actions={actions.length ? <div className="flex flex-wrap gap-2">{actions}</div> : undefined}
                />
            }
        >
            <Head title={name} />

            <div className="space-y-6">
                <Outcome application={application} />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('transfers.progress.title')}</CardTitle>
                        <StatusBadge status={status} />
                    </CardHeader>
                    <TransferProgress status={status} approvals={approvals} />
                </Card>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <div className="min-w-0 space-y-6">
                        <Card>
                            <CardHeader><CardTitle>{t('transfers.transferDetails')}</CardTitle></CardHeader>
                            <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                                <Detail label={t('transfers.destinationPosition')}>{positionTitle}</Detail>
                                <Detail label={t('transfers.currentPosition')}>
                                    {application.current_position ? localizedName(application.current_position.title_en, application.current_position.title_am, locale) : '—'}
                                </Detail>
                                <Detail label={t('transfers.releasingOrganization')}>{from}</Detail>
                                <Detail label={t('transfers.receivingOrganization')}>{to}</Detail>
                                <Detail label={t('common.submittedAt')}><LocalizedDateDisplay value={application.submitted_at} fallback="—" /></Detail>
                                <Detail label={t('transfers.effectiveDate')}><LocalizedDateDisplay value={application.effective_date} fallback={t('transfers.effectiveOnApproval')} /></Detail>
                                {application.implemented_at && (
                                    <Detail label={t('transfers.implementedAt')}><LocalizedDateDisplay value={application.implemented_at} /></Detail>
                                )}
                            </dl>
                            {application.applicant_notes && (
                                <div className="mt-5 border-t border-(--app-border) pt-4">
                                    <p className="text-xs font-medium text-(--app-muted-foreground)">{t('transfers.coverLetter')}</p>
                                    <p className="mt-1 text-sm whitespace-pre-line wrap-break-word text-(--app-foreground)">{application.applicant_notes}</p>
                                </div>
                            )}
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>{t('transfers.attachedDocuments')}</CardTitle></CardHeader>
                            <TransferDocumentList
                                documents={application.documents ?? []}
                                hrefFor={(document) => route('transfer-applications.documents.download', { transferApplication: application.id, document: document.id })}
                            />
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>{t('transfers.approvalChain')}</CardTitle></CardHeader>
                            {approvals.length === 0 ? (
                                <p className="text-sm text-(--app-muted-foreground)">{t('transfers.approvalsAfterSelection')}</p>
                            ) : (
                                <ol className="divide-y divide-(--app-border)">
                                    {approvals.map((approval) => (
                                        <li key={approval.id} className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-(--app-foreground)">{t(`transfers.approvalType${capitalize(approval.approval_type)}`)}</p>
                                                {approval.approver && (
                                                    <p className="text-xs text-(--app-muted-foreground)">
                                                        {approval.approver.name} · <LocalizedDateDisplay value={approval.decided_at} />
                                                    </p>
                                                )}
                                                {approval.rejection_reason && <p className="mt-1 text-sm wrap-break-word text-(--app-foreground)">{approval.rejection_reason}</p>}
                                            </div>
                                            <StatusBadge status={approval.status} />
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Card>
                    </div>

                    <Card className="self-start">
                        <CardHeader><CardTitle>{t('transfers.reviewHistory')}</CardTitle></CardHeader>
                        {(application.screening_reviews ?? []).length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noReviewYet')}</p>
                        ) : (
                            <ol className="space-y-4">
                                {(application.screening_reviews ?? []).map((review) => (
                                    <li key={review.id} className="border-s-2 border-(--app-border) ps-3">
                                        <p className="text-sm font-medium text-(--app-foreground)">{t(`transfers.screenAction${pascal(review.action)}`)}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">
                                            {review.reviewer?.name ?? '—'} · <LocalizedDateDisplay value={review.created_at} />
                                        </p>
                                        {review.notes && <p className="mt-1 text-sm wrap-break-word text-(--app-foreground)">{review.notes}</p>}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>
                </div>
            </div>

            <SelectDialog open={selectOpen} onClose={() => setSelectOpen(false)} application={application} />
        </AuthenticatedLayout>
    );
}

/** Why an application stopped, or why its implementation failed, in words. */
function Outcome({ application }: { application: TransferApplicationRecord }) {
    const { t } = useLocale();

    if (application.status === 'rejected' && application.rejected_reason) {
        return <Alert tone="danger" title={t('transfers.rejectedReason')}>{application.rejected_reason}</Alert>;
    }
    if (application.status === 'withdrawn' && application.withdrawal_reason) {
        return <Alert tone="warning" title={t('transfers.withdrawnReason')}>{application.withdrawal_reason}</Alert>;
    }
    if (application.status === 'implementation_failed' && application.implementation_failure) {
        return <Alert tone="danger" title={t('transfers.implementationFailed')}>{application.implementation_failure}</Alert>;
    }

    return null;
}

/** Selecting the candidate starts the approval chain; the effective date is optional. */
function SelectDialog({ open, onClose, application }: { open: boolean; onClose: () => void; application: TransferApplicationRecord }) {
    const { t } = useLocale();
    const form = useForm({ effective_date: '', notes: '' });
    const today = new Date().toISOString().slice(0, 10);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '')));
        form.post(route('transfer-applications.select', application.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onClose(); },
        });
    }

    return (
        <AppDialog
            open={open}
            onClose={onClose}
            title={t('transfers.select')}
            description={t('transfers.selectHint')}
            maxWidth="lg"
            footer={<>
                <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                <Button type="submit" form="select-candidate" variant="primary" loading={form.processing}>{t('transfers.select')}</Button>
            </>}
        >
            <form id="select-candidate" onSubmit={submit} className="grid gap-4">
                <FormField id="effective-date" label={t('transfers.effectiveDate')} description={t('transfers.effectiveDateHint')} error={form.errors.effective_date}>
                    {({ id }) => <LocalizedDatePicker id={id} value={form.data.effective_date} min={today} onChange={(value) => form.setData('effective_date', value)} />}
                </FormField>
                <FormField id="select-notes" label={t('transfers.notes')} error={form.errors.notes}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} rows={3} maxLength={1000} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    )}
                </FormField>
            </form>
        </AppDialog>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 wrap-break-word text-(--app-foreground)">{children}</dd>
        </div>
    );
}

const capitalize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);
const pascal = (value: string) => value.split('_').map(capitalize).join('');
