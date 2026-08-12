import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CardRequestStatusBadge from '@/Components/IdCards/CardRequestStatusBadge';
import CardRequestTypeBadge from '@/Components/IdCards/CardRequestTypeBadge';
import CardDataChecklist from '@/Components/IdCards/CardDataChecklist';
import CardLifecycleTimeline from '@/Components/IdCards/CardLifecycleTimeline';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

type CardRequestData = {
    id: string;
    request_type?: string | null;
    status: string;
    request_reason?: string | null;
    verification_notes?: string | null;
    rejection_reason?: string | null;
    cancellation_reason?: string | null;
    submitted_at?: string | null;
    verified_at?: string | null;
    approved_at?: string | null;
    rejected_at?: string | null;
    cancelled_at?: string | null;
    employee?: {
        id: string;
        employee_number: string;
        full_name: string;
        status: string;
        photo_url?: string | null;
        photo_path?: string | null;
        current_assignment?: {
            organization?: { name_en: string } | null;
            position?: { title_en: string } | null;
        } | null;
    } | null;
    requested_by?: { id: string; name: string } | null;
    reviewed_by?: { id: string; name: string } | null;
    approved_by?: { id: string; name: string } | null;
    rejected_by?: { id: string; name: string } | null;
    cards?: Array<{ id: string; card_number: string; status: string }>;
};

type PageProps = {
    cardRequest: CardRequestData;
    can: {
        verify?: boolean;
        approve?: boolean;
        reject?: boolean;
        cancel?: boolean;
    };
};

// ─── Field ──────────────────────────────────────────────────────────────────

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-slate-500">
                {label}
            </dt>
            <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

// ─── Modal ───────────────────────────────────────────────────────────────────

function ConfirmModal({
    title,
    description,
    variant = 'primary',
    confirmLabel,
    children,
    processing,
    onConfirm,
    onClose,
}: {
    title: string;
    description?: string;
    variant?: 'primary' | 'danger' | 'warning';
    confirmLabel: string;
    children?: React.ReactNode;
    processing?: boolean;
    onConfirm: () => void;
    onClose: () => void;
}) {
    const { t } = useLocale();
    const variantCls: Record<string, string> = {
        primary: 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-500',
        danger:  'bg-red-600 hover:bg-red-700 focus:ring-red-500',
        warning: 'bg-orange-500 hover:bg-orange-600 focus:ring-orange-400',
    };
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="w-full max-w-md rounded-2xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
                <div className="flex items-start justify-between px-6 pt-5">
                    <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-4 rounded-lg p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-800"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                {description && (
                    <p className="mt-1 px-6 text-sm text-gray-500 dark:text-slate-400">{description}</p>
                )}
                {children && <div className="mt-4 px-6 space-y-3">{children}</div>}
                <div className="mt-5 flex justify-end gap-2 rounded-b-2xl border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >
                        {t('common.cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={processing}
                        className={`rounded-lg px-4 py-2 text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60 ${variantCls[variant]}`}
                    >
                        {processing ? t('common.saving') : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

const inputCls = 'w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500';

// ─── Main ────────────────────────────────────────────────────────────────────

export default function CardRequestShow({ cardRequest, can }: PageProps) {
    const { t } = useLocale();
    const [modal, setModal] = useState<string | null>(null);

    const verifyForm  = useForm({ notes: '' });
    const approveForm = useForm({ notes: '' });
    const rejectForm  = useForm({ rejection_reason: '' });
    const cancelForm  = useForm({ cancellation_reason: '' });

    const isFinal = ['approved', 'rejected', 'cancelled'].includes(cardRequest.status);

    // Build timeline events from dates — pass raw ISO, timeline renders via LocalizedDateDisplay
    const timelineEvents: Record<string, { timestamp?: string | null; actor?: string | null }> = {};
    if (cardRequest.submitted_at) timelineEvents['submitted'] = { timestamp: cardRequest.submitted_at, actor: cardRequest.requested_by?.name };
    if (cardRequest.verified_at)  timelineEvents['verified']  = { timestamp: cardRequest.verified_at,  actor: cardRequest.reviewed_by?.name };
    if (cardRequest.approved_at)  timelineEvents['approved']  = { timestamp: cardRequest.approved_at,  actor: cardRequest.approved_by?.name };
    if (cardRequest.rejected_at)  timelineEvents['rejected']  = { timestamp: cardRequest.rejected_at,  actor: cardRequest.rejected_by?.name };

    const hasAnyAction = can.verify || can.approve || can.reject || can.cancel;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('card-requests.index')}
                    title={t('idCards.requestDetails')}
                    description={cardRequest.employee?.full_name ?? ''}
                />
            }
        >
            <Head title={t('idCards.requestDetails')} />

            <div className="grid gap-6 lg:grid-cols-[1fr_300px]">

                {/* ── Main column ─────────────────────────────────────── */}
                <div className="space-y-5">

                    {/* Header: status + type + meta */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-wrap items-center gap-2 mb-4">
                            <CardRequestStatusBadge status={cardRequest.status} />
                            {cardRequest.request_type && (
                                <CardRequestTypeBadge type={cardRequest.request_type} />
                            )}
                            {cardRequest.submitted_at && (
                                <span className="ml-auto text-xs text-gray-400 dark:text-slate-500">
                                    <LocalizedDateDisplay value={cardRequest.submitted_at} withTime />
                                </span>
                            )}
                        </div>

                        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                            <Field label={t('employees.employeeNumber')}>
                                <span className="font-mono font-medium">
                                    {cardRequest.employee?.employee_number ?? '—'}
                                </span>
                            </Field>
                            <Field label={t('organizations.organization')}>
                                {cardRequest.employee?.current_assignment?.organization?.name_en ?? '—'}
                            </Field>
                            <Field label={t('employees.columnPosition')}>
                                {cardRequest.employee?.current_assignment?.position?.title_en ?? '—'}
                            </Field>
                            {cardRequest.requested_by && (
                                <Field label={t('idCards.requestedBy')}>
                                    {cardRequest.requested_by.name}
                                </Field>
                            )}
                            {cardRequest.reviewed_by && (
                                <Field label={t('idCards.reviewedBy')}>
                                    {cardRequest.reviewed_by.name}
                                </Field>
                            )}
                            {cardRequest.approved_by && (
                                <Field label={t('idCards.approvedBy')}>
                                    {cardRequest.approved_by.name}
                                </Field>
                            )}
                            {cardRequest.rejected_by && (
                                <Field label={t('idCards.rejectedBy')}>
                                    <span className="text-red-600 dark:text-red-400">{cardRequest.rejected_by.name}</span>
                                </Field>
                            )}

                            {cardRequest.request_reason && (
                                <div className="col-span-full">
                                    <Field label={t('idCards.requestReason')}>
                                        {cardRequest.request_reason}
                                    </Field>
                                </div>
                            )}
                            {cardRequest.verification_notes && (
                                <div className="col-span-full rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 dark:border-indigo-900/40 dark:bg-indigo-900/10">
                                    <dt className="text-xs font-medium uppercase tracking-wide text-indigo-500 dark:text-indigo-400">
                                        {t('idCards.verifyRequestData')}
                                    </dt>
                                    <dd className="mt-1 text-sm text-indigo-700 dark:text-indigo-300">
                                        {cardRequest.verification_notes}
                                    </dd>
                                </div>
                            )}
                            {cardRequest.rejection_reason && (
                                <div className="col-span-full rounded-xl border border-red-100 bg-red-50 px-4 py-3 dark:border-red-900/40 dark:bg-red-900/10">
                                    <dt className="text-xs font-medium uppercase tracking-wide text-red-500 dark:text-red-400">
                                        {t('idCards.rejectionReason')}
                                    </dt>
                                    <dd className="mt-1 text-sm text-red-700 dark:text-red-300">
                                        {cardRequest.rejection_reason}
                                    </dd>
                                </div>
                            )}
                            {cardRequest.cancellation_reason && (
                                <div className="col-span-full rounded-xl border border-orange-100 bg-orange-50 px-4 py-3 dark:border-orange-900/40 dark:bg-orange-900/10">
                                    <dt className="text-xs font-medium uppercase tracking-wide text-orange-500 dark:text-orange-400">
                                        {t('idCards.cancellationReason')}
                                    </dt>
                                    <dd className="mt-1 text-sm text-orange-700 dark:text-orange-300">
                                        {cardRequest.cancellation_reason}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </section>

                    {/* Employee summary */}
                    {cardRequest.employee && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                                {t('employees.title')}
                            </h3>
                            <div className="flex items-center gap-4">
                                {cardRequest.employee.photo_url ? (
                                    <img
                                        src={cardRequest.employee.photo_url}
                                        alt={cardRequest.employee.full_name}
                                        className="h-14 w-14 rounded-xl object-cover ring-2 ring-gray-100 dark:ring-slate-700"
                                    />
                                ) : (
                                    <div className="h-14 w-14 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-400 dark:text-slate-500 text-lg font-bold shrink-0">
                                        {cardRequest.employee.full_name?.charAt(0) ?? '?'}
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <p className="font-semibold text-gray-900 dark:text-slate-100 truncate">
                                        {cardRequest.employee.full_name}
                                    </p>
                                    <p className="text-sm text-gray-500 dark:text-slate-400">
                                        {cardRequest.employee.employee_number}
                                        {cardRequest.employee.current_assignment?.position?.title_en && (
                                            <span className="mx-1.5 text-gray-300 dark:text-slate-600">·</span>
                                        )}
                                        {cardRequest.employee.current_assignment?.position?.title_en}
                                    </p>
                                    <p className="text-xs text-gray-400 dark:text-slate-500 truncate">
                                        {cardRequest.employee.current_assignment?.organization?.name_en}
                                    </p>
                                </div>
                                <Link
                                    href={route('employees.show', cardRequest.employee.id)}
                                    className="ml-auto shrink-0 text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                >
                                    {t('common.view')}
                                </Link>
                            </div>
                        </section>
                    )}

                    {/* Linked cards */}
                    {(cardRequest.cards?.length ?? 0) > 0 && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                                {t('idCards.title')}
                            </h3>
                            <div className="space-y-2">
                                {cardRequest.cards?.map((card) => (
                                    <Link
                                        key={card.id}
                                        href={route('id-cards.show', card.id)}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50 px-4 py-2.5 hover:bg-gray-100 transition-colors dark:border-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700"
                                    >
                                        <span className="font-mono text-sm font-medium text-gray-900 dark:text-slate-100">
                                            {card.card_number}
                                        </span>
                                        <CardStatusBadge status={card.status} />
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}
                </div>

                {/* ── Sidebar ──────────────────────────────────────────── */}
                <div className="space-y-4">

                    {/* Data checklist */}
                    {cardRequest.employee && (
                        <CardDataChecklist employee={cardRequest.employee} />
                    )}

                    {/* Actions */}
                    {hasAnyAction && !isFinal && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                                {t('common.actions')}
                            </h4>
                            <div className="space-y-2">
                                {can.verify && (
                                    <button
                                        type="button"
                                        onClick={() => setModal('verify')}
                                        disabled={verifyForm.processing}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-sky-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-60 transition-colors"
                                    >
                                        <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                        </svg>
                                        {t('idCards.verifyRequestData')}
                                    </button>
                                )}
                                {can.approve && (
                                    <button
                                        type="button"
                                        onClick={() => setModal('approve')}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 transition-colors"
                                    >
                                        <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                        </svg>
                                        {t('idCards.approveRequest')}
                                    </button>
                                )}
                                {(can.reject || can.cancel) && (
                                    <div className="border-t border-gray-100 dark:border-slate-800 my-1" />
                                )}
                                {can.reject && (
                                    <button
                                        type="button"
                                        onClick={() => setModal('reject')}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-red-200 bg-white px-3 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors dark:border-red-800 dark:bg-transparent dark:text-red-400 dark:hover:bg-red-900/20"
                                    >
                                        <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                        </svg>
                                        {t('idCards.rejectRequest')}
                                    </button>
                                )}
                                {can.cancel && (
                                    <button
                                        type="button"
                                        onClick={() => setModal('cancel')}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 transition-colors dark:border-slate-600 dark:bg-transparent dark:text-slate-300 dark:hover:bg-slate-800"
                                    >
                                        {t('idCards.cancelRequest')}
                                    </button>
                                )}
                            </div>
                        </section>
                    )}

                    {/* Lifecycle timeline */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <CardLifecycleTimeline
                            cardStatus={cardRequest.status}
                            events={timelineEvents}
                        />
                    </section>
                </div>
            </div>

            {/* ─── Modals ──────────────────────────────────────────────── */}

            {modal === 'verify' && (
                <ConfirmModal
                    title={t('idCards.verifyRequestData')}
                    description={t('idCards.confirmVerify')}
                    variant="primary"
                    confirmLabel={t('idCards.verifyRequestData')}
                    processing={verifyForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        verifyForm.post(route('card-requests.verify', cardRequest.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                            {t('common.notes')}
                        </label>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.verificationNotesPlaceholder')}
                            rows={3}
                            value={verifyForm.data.notes}
                            onChange={(e) => verifyForm.setData('notes', e.target.value)}
                        />
                    </div>
                </ConfirmModal>
            )}

            {modal === 'approve' && (
                <ConfirmModal
                    title={t('idCards.approveRequest')}
                    description={t('idCards.confirmApprove')}
                    variant="primary"
                    confirmLabel={t('idCards.approveRequest')}
                    processing={approveForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        approveForm.post(route('card-requests.approve', cardRequest.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                            {t('common.notes')}
                        </label>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.approvalNotesPlaceholder')}
                            rows={2}
                            value={approveForm.data.notes}
                            onChange={(e) => approveForm.setData('notes', e.target.value)}
                        />
                    </div>
                </ConfirmModal>
            )}

            {modal === 'reject' && (
                <ConfirmModal
                    title={t('idCards.rejectRequest')}
                    description={t('idCards.confirmReject')}
                    variant="danger"
                    confirmLabel={t('idCards.rejectRequest')}
                    processing={rejectForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        if (!rejectForm.data.rejection_reason.trim()) return;
                        rejectForm.post(route('card-requests.reject', cardRequest.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                            {t('idCards.rejectionReason')}
                            <span className="ml-1 text-red-500">*</span>
                        </label>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.rejectionReasonPlaceholder')}
                            rows={3}
                            value={rejectForm.data.rejection_reason}
                            onChange={(e) => rejectForm.setData('rejection_reason', e.target.value)}
                        />
                        {rejectForm.errors.rejection_reason && (
                            <p className="mt-1 text-xs text-red-500">{rejectForm.errors.rejection_reason}</p>
                        )}
                    </div>
                </ConfirmModal>
            )}

            {modal === 'cancel' && (
                <ConfirmModal
                    title={t('idCards.cancelRequest')}
                    description={t('idCards.confirmCancel')}
                    variant="warning"
                    confirmLabel={t('idCards.cancelRequest')}
                    processing={cancelForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        cancelForm.post(route('card-requests.cancel', cardRequest.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                            {t('idCards.cancellationReason')}
                        </label>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.cancellationReasonPlaceholder')}
                            rows={2}
                            value={cancelForm.data.cancellation_reason}
                            onChange={(e) => cancelForm.setData('cancellation_reason', e.target.value)}
                        />
                    </div>
                </ConfirmModal>
            )}
        </AuthenticatedLayout>
    );
}
