import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import CardLifecycleTimeline from '@/Components/IdCards/CardLifecycleTimeline';
import IdCardFront from '@/Components/IdCards/IdCardFront';
import IdCardBack from '@/Components/IdCards/IdCardBack';
import IdCardPortraitFront from '@/Components/IdCards/IdCardPortraitFront';
import IdCardPortraitBack from '@/Components/IdCards/IdCardPortraitBack';
import CardPrintExportModal from '@/Components/IdCards/CardPrintExportModal';
import CardPortraitPrintExportModal from '@/Components/IdCards/CardPortraitPrintExportModal';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

type CardData = {
    id: string;
    card_number: string;
    status: string;
    issued_at?: string | null;
    activated_at?: string | null;
    expires_at?: string | null;
    revoked_at?: string | null;
    revoke_reason?: string | null;
    notes?: string | null;
    qr_payload?: string | null;
    public_card_uuid?: string | null;
    employee?: {
        full_name: string;
        metadata?: { name_en?: string | null; name_am?: string | null } | null;
        name_en?: string | null;
        gender?: string | null;
        employee_number: string;
        status: string;
        photo_path?: string | null;
        photo_url?: string | null;
        current_assignment?: {
            assignment_status?: string | null;
            organization?: { name_en: string; name_am?: string | null; code: string; logo_url?: string | null } | null;
            organization_unit?: { name_en: string; name_am?: string | null } | null;
            position?: { title_en: string; title_am?: string | null; job_position_code?: string | null; grade_level?: string | null } | null;
        } | null;
    } | null;
    card_request?: {
        requester?: { name: string } | null;
        reviewer?: { name: string } | null;
        approver?: { name: string } | null;
    } | null;
    issuance?: {
        issuer?: { name: string } | null;
    } | null;
    previous_card?: { id: string; card_number: string } | null;
    replacement_card?: { id: string; card_number: string } | null;
};

type Can = {
    view?: boolean;
    update?: boolean;
    print?: boolean;
    issue?: boolean;
    activate?: boolean;
    reportLost?: boolean;
    reportDamaged?: boolean;
    replace?: boolean;
    revoke?: boolean;
    printAnytime?: boolean;
    exportPng?: boolean;
};

type PageProps = {
    card: CardData;
    can: Can;
};

// ── Shared field display ─────────────────────────────────────────────────────

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

// ── Modal ────────────────────────────────────────────────────────────────────

type ModalVariant = 'primary' | 'danger' | 'warning';

function Modal({
    title,
    description,
    variant = 'danger',
    confirmLabel,
    onConfirm,
    onClose,
    processing,
    children,
}: {
    title: string;
    description: string;
    variant?: ModalVariant;
    confirmLabel?: string;
    onConfirm: () => void;
    onClose: () => void;
    processing?: boolean;
    children?: React.ReactNode;
}) {
    const { t } = useLocale();
    const confirmCls: Record<ModalVariant, string> = {
        primary: 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500',
        danger: 'bg-red-600 hover:bg-red-700 focus:ring-red-500',
        warning: 'bg-orange-500 hover:bg-orange-600 focus:ring-orange-500',
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="w-full max-w-md rounded-2xl bg-white shadow-2xl dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700">
                <div className="flex items-start justify-between px-6 pt-5">
                    <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-4 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p className="mt-1 px-6 text-sm text-gray-500 dark:text-slate-400">{description}</p>
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
                        className={`rounded-lg px-4 py-2 text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60 ${confirmCls[variant]}`}
                    >
                        {processing ? t('common.saving') : (confirmLabel ?? t('common.confirm'))}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Action button ────────────────────────────────────────────────────────────

function ActionButton({
    label,
    onClick,
    disabled,
    variant,
    icon,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    variant: 'primary' | 'success' | 'danger' | 'warning' | 'neutral' | 'ghost-danger';
    icon: React.ReactNode;
}) {
    const cls: Record<typeof variant, string> = {
        primary:      'bg-indigo-600 text-white hover:bg-indigo-700',
        success:      'bg-emerald-600 text-white hover:bg-emerald-700',
        danger:       'bg-red-600 text-white hover:bg-red-700',
        warning:      'bg-orange-500 text-white hover:bg-orange-600',
        neutral:      'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-transparent dark:text-slate-300 dark:hover:bg-slate-800',
        'ghost-danger':'border border-red-200 bg-white text-red-700 hover:bg-red-50 dark:border-red-800 dark:bg-transparent dark:text-red-400 dark:hover:bg-red-900/20',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={`flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors disabled:opacity-60 ${cls[variant]}`}
        >
            <span className="shrink-0">{icon}</span>
            {label}
        </button>
    );
}

// ── Icons ────────────────────────────────────────────────────────────────────

const Icon = {
    send: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12zm0 0h7.5" />
        </svg>
    ),
    check: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
        </svg>
    ),
    lost: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z" />
        </svg>
    ),
    damaged: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="m11.42 15.17 1.57-1.57M8.58 12.83l1.57-1.57M11.44 5.06 4.5 12l2.96 2.96L5.1 17.32l1.58 1.58 2.36-2.36L12 19.5l6.94-6.94-7.5-7.5ZM8 8l8 8" />
        </svg>
    ),
    replace: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
        </svg>
    ),
    revoke: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
        </svg>
    ),
    print: (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5z" />
        </svg>
    ),
};

// ── Label / textarea ─────────────────────────────────────────────────────────

function FormField({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{label}</label>
            {children}
        </div>
    );
}

const inputCls = 'w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500';

// ── Main component ───────────────────────────────────────────────────────────

export default function IdCardShow({ card, can }: PageProps) {
    const { t, locale } = useLocale();
    const calendarSystem = useCalendarSystem();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [modal, setModal] = useState<string | null>(null);
    const [cardDesign, setCardDesign] = useState<'landscape' | 'portrait'>('landscape');
    const [portraitModal, setPortraitModal] = useState<{ open: boolean; action: 'print' | 'export_png' }>({
        open: false, action: 'export_png',
    });
    const [exportModal, setExportModal] = useState<{ open: boolean; action: 'print' | 'export_png' }>({
        open: false,
        action: 'export_png',
    });

    const issueForm    = useForm({ issued_to: '', received_by: '' });
    const activateForm = useForm({ notes: '' });
    const lostForm     = useForm({ reason: '' });
    const damagedForm  = useForm({ reason: '' });
    const replaceForm  = useForm({ reason: '' });
    const revokeForm   = useForm({ reason: '' });

    const fmtDate = (v?: string | null) =>
        v ? (formatDateDisplay(v.slice(0, 10), calendarSystem, locale) || v.slice(0, 10)) : undefined;

    const hasAnyAction = can.issue || can.activate || can.reportLost || can.reportDamaged || can.replace || can.revoke;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('id-cards.index')}
                    title={card.card_number}
                    description={card.employee?.full_name ?? ''}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.print && (
                                <Link
                                    href={route('id-cards.preview', card.id)}
                                    className="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                                >
                                    {Icon.print}
                                    {t('idCards.previewBeforePrint')}
                                </Link>
                            )}
                            {can.printAnytime && (
                                <button
                                    type="button"
                                    onClick={() => setExportModal({ open: true, action: 'print' })}
                                    className="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                                >
                                    {Icon.print}
                                    {t('idCards.printCard')}
                                </button>
                            )}
                            {can.exportPng && (
                                <button
                                    type="button"
                                    onClick={() => setExportModal({ open: true, action: 'export_png' })}
                                    className="flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                    {t('idCards.exportPng')}
                                </button>
                            )}
                            <Link
                                href={route('id-cards.index')}
                                className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            >
                                {t('common.back')}
                            </Link>
                        </div>
                    }
                />
            }
        >
            <Head title={card.card_number} />

            {/* Action error banner */}
            {errors.action && (
                <div className="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-400">
                    <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <span>{errors.action}</span>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_300px]">

                {/* ── Left column ─────────────────────────────────────────── */}
                <div className="space-y-5">

                    {/* Card visual preview */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
                                {t('common.preview')}
                            </h2>
                            <div className="flex items-center gap-3">
                                {/* Design toggle */}
                                <div className="flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-slate-700 dark:bg-slate-800">
                                    <button
                                        type="button"
                                        onClick={() => setCardDesign('landscape')}
                                        className={[
                                            'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            cardDesign === 'landscape'
                                                ? 'bg-white text-gray-900 shadow-sm dark:bg-slate-700 dark:text-slate-100'
                                                : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200',
                                        ].join(' ')}
                                    >
                                        {/* landscape icon */}
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 16 10" fill="none" stroke="currentColor" strokeWidth={1.5}>
                                            <rect x="0.75" y="0.75" width="14.5" height="8.5" rx="1.5" />
                                        </svg>
                                        Landscape
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCardDesign('portrait')}
                                        className={[
                                            'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            cardDesign === 'portrait'
                                                ? 'bg-white text-gray-900 shadow-sm dark:bg-slate-700 dark:text-slate-100'
                                                : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200',
                                        ].join(' ')}
                                    >
                                        {/* portrait icon */}
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 10 16" fill="none" stroke="currentColor" strokeWidth={1.5}>
                                            <rect x="0.75" y="0.75" width="8.5" height="14.5" rx="1.5" />
                                        </svg>
                                        Portrait
                                    </button>
                                </div>
                                <CardStatusBadge status={card.status} />
                            </div>
                        </div>

                        {cardDesign === 'landscape' ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <IdCardFront
                                    cardNumber={card.card_number}
                                    fullName={card.employee?.name_en ?? card.employee?.metadata?.name_en ?? card.employee?.full_name}
                                    fullNameAm={card.employee?.metadata?.name_am ?? card.employee?.full_name}
                                    employeeNumber={card.employee?.employee_number}
                                    organizationName={card.employee?.current_assignment?.organization?.name_en}
                                    organizationNameAm={card.employee?.current_assignment?.organization?.name_am}
                                    organizationUnitName={locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am : card.employee?.current_assignment?.organization_unit?.name_en}
                                    organizationLogoUrl={card.employee?.current_assignment?.organization?.logo_url}
                                    positionTitle={card.employee?.current_assignment?.position?.title_en}
                                    positionTitleAm={card.employee?.current_assignment?.position?.title_am}
                                    positionCode={card.employee?.current_assignment?.position?.job_position_code}
                                    jobGrade={card.employee?.current_assignment?.position?.grade_level}
                                    employmentStatus={card.employee?.status}
                                    gender={card.employee?.gender}
                                    photoUrl={card.employee?.photo_url}
                                    issueDate={fmtDate(card.issued_at)}
                                    expiryDate={fmtDate(card.expires_at)}
                                    status={card.status}
                                />
                                <IdCardBack
                                    cardNumber={card.card_number}
                                    qrValue={card.public_card_uuid ? route('id-cards.verify.public', card.public_card_uuid) : null}
                                    issueDate={fmtDate(card.issued_at)}
                                    expiryDate={fmtDate(card.expires_at)}
                                />
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="flex flex-wrap justify-center gap-6">
                                    <IdCardPortraitFront
                                        cardNumber={card.card_number}
                                        fullName={card.employee?.name_en ?? card.employee?.metadata?.name_en ?? card.employee?.full_name}
                                        fullNameAm={card.employee?.metadata?.name_am ?? card.employee?.full_name}
                                        employeeNumber={card.employee?.employee_number}
                                        organizationName={card.employee?.current_assignment?.organization?.name_en}
                                        organizationNameAm={card.employee?.current_assignment?.organization?.name_am}
                                        organizationUnitName={locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am : card.employee?.current_assignment?.organization_unit?.name_en}
                                        organizationLogoUrl={card.employee?.current_assignment?.organization?.logo_url}
                                        positionTitle={card.employee?.current_assignment?.position?.title_en}
                                        positionTitleAm={card.employee?.current_assignment?.position?.title_am}
                                        positionCode={card.employee?.current_assignment?.position?.job_position_code}
                                        jobGrade={card.employee?.current_assignment?.position?.grade_level}
                                        employmentStatus={card.employee?.status}
                                        gender={card.employee?.gender}
                                        photoUrl={card.employee?.photo_url}
                                        issueDate={fmtDate(card.issued_at)}
                                        expiryDate={fmtDate(card.expires_at)}
                                        status={card.status}
                                    />
                                    <IdCardPortraitBack
                                        cardNumber={card.card_number}
                                        qrValue={card.public_card_uuid ? route('id-cards.verify.public', card.public_card_uuid) : null}
                                        issueDate={fmtDate(card.issued_at)}
                                        expiryDate={fmtDate(card.expires_at)}
                                    />
                                </div>
                                {/* Portrait print / export actions */}
                                <div className="flex justify-center gap-2 pt-1">
                                    {can.printAnytime && (
                                        <button
                                            type="button"
                                            onClick={() => setPortraitModal({ open: true, action: 'print' })}
                                            className="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                                        >
                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5z" />
                                            </svg>
                                            {t('idCards.printCard')}
                                        </button>
                                    )}
                                    {can.exportPng && (
                                        <button
                                            type="button"
                                            onClick={() => setPortraitModal({ open: true, action: 'export_png' })}
                                            className="flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50"
                                        >
                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                            {t('idCards.exportPng')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </section>

                    {/* Card details */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">
                            {t('common.details')}
                        </h2>
                        <dl className="grid grid-cols-2 gap-x-8 gap-y-5 sm:grid-cols-3">
                            <Field label={t('idCards.cardNumber')}>
                                <span className="font-mono font-medium">{card.card_number}</span>
                            </Field>
                            <Field label={t('common.status')}>
                                <CardStatusBadge status={card.status} />
                            </Field>
                            <Field label={t('idCards.expiryDate')}>
                                <LocalizedDateDisplay value={card.expires_at} />
                            </Field>
                            {card.issued_at && (
                                <Field label={t('idCards.issuedAt')}>
                                    <LocalizedDateDisplay value={card.issued_at} withTime />
                                </Field>
                            )}
                            {card.activated_at && (
                                <Field label={t('idCards.activatedAt')}>
                                    <LocalizedDateDisplay value={card.activated_at} withTime />
                                </Field>
                            )}
                            {card.revoked_at && (
                                <Field label={t('idCards.revokedAt')}>
                                    <span className="text-red-600 dark:text-red-400"><LocalizedDateDisplay value={card.revoked_at} withTime /></span>
                                </Field>
                            )}
                            <Field label={t('employees.columnPosition')}>
                                {card.employee?.current_assignment?.position?.title_en ?? '—'}
                            </Field>
                            <Field label={t('organizations.organization')}>
                                {card.employee?.current_assignment?.organization?.name_en ?? '—'}
                            </Field>
                            {card.revoke_reason && (
                                <div className="col-span-full rounded-xl border border-red-100 bg-red-50 px-4 py-3 dark:border-red-900/40 dark:bg-red-900/10">
                                    <dt className="text-xs font-medium uppercase tracking-wide text-red-500 dark:text-red-400">
                                        {t('idCards.revocationReason')}
                                    </dt>
                                    <dd className="mt-1 text-sm text-red-700 dark:text-red-300">{card.revoke_reason}</dd>
                                </div>
                            )}
                            {card.notes && (
                                <div className="col-span-full">
                                    <Field label={t('common.notes')}>
                                        {card.notes}
                                    </Field>
                                </div>
                            )}
                        </dl>
                    </section>

                    {/* Provenance: request + issuance + linked cards */}
                    {(card.card_request || card.issuance || card.previous_card || card.replacement_card) && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">
                                {t('idCards.history')}
                            </h2>
                            <dl className="grid grid-cols-2 gap-x-8 gap-y-5 sm:grid-cols-3">
                                {card.card_request?.requester && (
                                    <Field label={t('idCards.requestedBy')}>
                                        {card.card_request.requester.name}
                                    </Field>
                                )}
                                {card.card_request?.reviewer && (
                                    <Field label={t('idCards.reviewedBy')}>
                                        {card.card_request.reviewer.name}
                                    </Field>
                                )}
                                {card.card_request?.approver && (
                                    <Field label={t('idCards.approvedBy')}>
                                        {card.card_request.approver.name}
                                    </Field>
                                )}
                                {card.issuance?.issuer && (
                                    <Field label={t('idCards.issuedBy')}>
                                        {card.issuance.issuer.name}
                                    </Field>
                                )}
                                {card.previous_card && (
                                    <Field label={t('idCards.previousCard')}>
                                        <Link
                                            href={route('id-cards.show', card.previous_card.id)}
                                            className="font-mono text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {card.previous_card.card_number}
                                        </Link>
                                    </Field>
                                )}
                                {card.replacement_card && (
                                    <Field label={t('idCards.replacementCard')}>
                                        <Link
                                            href={route('id-cards.show', card.replacement_card.id)}
                                            className="font-mono text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {card.replacement_card.card_number}
                                        </Link>
                                    </Field>
                                )}
                            </dl>
                        </section>
                    )}
                </div>

                {/* ── Right sidebar ────────────────────────────────────────── */}
                <div className="space-y-5">

                    {/* Actions */}
                    {hasAnyAction && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                                {t('common.actions')}
                            </h2>
                            <div className="space-y-2">
                                {can.issue && (
                                    <ActionButton
                                        label={t('idCards.issueCard')}
                                        variant="primary"
                                        icon={Icon.send}
                                        onClick={() => setModal('issue')}
                                    />
                                )}
                                {can.activate && (
                                    <ActionButton
                                        label={t('idCards.activateCard')}
                                        variant="success"
                                        icon={Icon.check}
                                        disabled={activateForm.processing}
                                        onClick={() => setModal('activate')}
                                    />
                                )}
                                {(can.reportLost || can.reportDamaged) && (
                                    <div className="my-1 border-t border-gray-100 dark:border-slate-800" />
                                )}
                                {can.reportLost && (
                                    <ActionButton
                                        label={t('idCards.reportLostCard')}
                                        variant="danger"
                                        icon={Icon.lost}
                                        onClick={() => setModal('lost')}
                                    />
                                )}
                                {can.reportDamaged && (
                                    <ActionButton
                                        label={t('idCards.reportDamagedCard')}
                                        variant="warning"
                                        icon={Icon.damaged}
                                        onClick={() => setModal('damaged')}
                                    />
                                )}
                                {(can.replace || can.revoke) && (
                                    <div className="my-1 border-t border-gray-100 dark:border-slate-800" />
                                )}
                                {can.replace && (
                                    <ActionButton
                                        label={t('idCards.replaceCard')}
                                        variant="neutral"
                                        icon={Icon.replace}
                                        onClick={() => setModal('replace')}
                                    />
                                )}
                                {can.revoke && (
                                    <ActionButton
                                        label={t('idCards.revokeCard')}
                                        variant="ghost-danger"
                                        icon={Icon.revoke}
                                        onClick={() => setModal('revoke')}
                                    />
                                )}
                            </div>
                        </section>
                    )}

                    {/* Lifecycle timeline */}
                    <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <CardLifecycleTimeline cardStatus={card.status} />
                    </section>
                </div>
            </div>

            {/* ── Modals ───────────────────────────────────────────────────── */}

            {modal === 'issue' && (
                <Modal
                    title={t('idCards.issueCard')}
                    description={t('idCards.confirmIssue')}
                    variant="primary"
                    confirmLabel={t('idCards.issueCard')}
                    processing={issueForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        issueForm.post(route('id-cards.issue', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('idCards.issuedTo')}>
                        <input
                            className={inputCls}
                            placeholder={t('idCards.issuedToPlaceholder')}
                            value={issueForm.data.issued_to}
                            onChange={(e) => issueForm.setData('issued_to', e.target.value)}
                        />
                        {issueForm.errors.issued_to && (
                            <p className="mt-1 text-xs text-red-500">{issueForm.errors.issued_to}</p>
                        )}
                    </FormField>
                    <FormField label={t('idCards.receivedBy')}>
                        <input
                            className={inputCls}
                            placeholder={t('idCards.receivedByPlaceholder')}
                            value={issueForm.data.received_by}
                            onChange={(e) => issueForm.setData('received_by', e.target.value)}
                        />
                        {issueForm.errors.received_by && (
                            <p className="mt-1 text-xs text-red-500">{issueForm.errors.received_by}</p>
                        )}
                    </FormField>
                </Modal>
            )}

            {modal === 'activate' && (
                <Modal
                    title={t('idCards.activateCard')}
                    description={t('idCards.confirmActivate')}
                    variant="primary"
                    confirmLabel={t('idCards.activateCard')}
                    processing={activateForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        activateForm.post(route('id-cards.activate', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('common.notes')}>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.activationNotes')}
                            rows={2}
                            value={activateForm.data.notes}
                            onChange={(e) => activateForm.setData('notes', e.target.value)}
                        />
                    </FormField>
                </Modal>
            )}

            {modal === 'lost' && (
                <Modal
                    title={t('idCards.reportLostCard')}
                    description={t('idCards.confirmLost')}
                    variant="danger"
                    confirmLabel={t('idCards.reportLostCard')}
                    processing={lostForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        lostForm.post(route('id-cards.report-lost', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('idCards.incidentReason')}>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.incidentReasonPlaceholder')}
                            rows={3}
                            value={lostForm.data.reason}
                            onChange={(e) => lostForm.setData('reason', e.target.value)}
                        />
                        {lostForm.errors.reason && (
                            <p className="mt-1 text-xs text-red-500">{lostForm.errors.reason}</p>
                        )}
                    </FormField>
                </Modal>
            )}

            {modal === 'damaged' && (
                <Modal
                    title={t('idCards.reportDamagedCard')}
                    description={t('idCards.confirmDamaged')}
                    variant="warning"
                    confirmLabel={t('idCards.reportDamagedCard')}
                    processing={damagedForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        damagedForm.post(route('id-cards.report-damaged', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('idCards.incidentReason')}>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.incidentReasonPlaceholder')}
                            rows={3}
                            value={damagedForm.data.reason}
                            onChange={(e) => damagedForm.setData('reason', e.target.value)}
                        />
                        {damagedForm.errors.reason && (
                            <p className="mt-1 text-xs text-red-500">{damagedForm.errors.reason}</p>
                        )}
                    </FormField>
                </Modal>
            )}

            {modal === 'replace' && (
                <Modal
                    title={t('idCards.replaceCard')}
                    description={t('idCards.confirmReplace')}
                    variant="primary"
                    confirmLabel={t('idCards.submitRequest')}
                    processing={replaceForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        replaceForm.post(route('id-cards.replace', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('idCards.requestReason')}>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.requestReasonPlaceholder')}
                            rows={3}
                            value={replaceForm.data.reason}
                            onChange={(e) => replaceForm.setData('reason', e.target.value)}
                        />
                        {replaceForm.errors.reason && (
                            <p className="mt-1 text-xs text-red-500">{replaceForm.errors.reason}</p>
                        )}
                    </FormField>
                </Modal>
            )}

            {modal === 'revoke' && (
                <Modal
                    title={t('idCards.revokeCard')}
                    description={t('idCards.confirmRevoke')}
                    variant="danger"
                    confirmLabel={t('idCards.revokeCard')}
                    processing={revokeForm.processing}
                    onClose={() => setModal(null)}
                    onConfirm={() => {
                        revokeForm.post(route('id-cards.revoke', card.id), {
                            onSuccess: () => setModal(null),
                        });
                    }}
                >
                    <FormField label={t('idCards.revocationReason')}>
                        <textarea
                            className={inputCls}
                            placeholder={t('idCards.revocationReasonPlaceholder')}
                            rows={3}
                            value={revokeForm.data.reason}
                            onChange={(e) => revokeForm.setData('reason', e.target.value)}
                        />
                        {revokeForm.errors.reason && (
                            <p className="mt-1 text-xs text-red-500">{revokeForm.errors.reason}</p>
                        )}
                    </FormField>
                </Modal>
            )}

            <CardPortraitPrintExportModal
                card={{
                    id: card.id,
                    card_number: card.card_number,
                    status: card.status,
                    issued_at: card.issued_at,
                    expires_at: card.expires_at,
                    public_card_uuid: card.public_card_uuid,
                    can: {
                        printAnytime: can.printAnytime,
                        exportPng: can.exportPng,
                    },
                    employee: card.employee ? {
                        full_name: card.employee.full_name,
                        metadata: card.employee.metadata,
                        gender: card.employee.gender,
                        status: card.employee.status,
                        employee_number: card.employee.employee_number,
                        photo_url: card.employee.photo_url,
                        current_assignment: card.employee.current_assignment ? {
                            organization: card.employee.current_assignment.organization
                                ? { name_en: card.employee.current_assignment.organization.name_en, logo_url: card.employee.current_assignment.organization.logo_url }
                                : null,
                            position: card.employee.current_assignment.position
                                ? { title_en: card.employee.current_assignment.position.title_en }
                                : null,
                        } : null,
                    } : null,
                }}
                isOpen={portraitModal.open}
                initialAction={portraitModal.action}
                onClose={() => setPortraitModal((prev) => ({ ...prev, open: false }))}
            />

            <CardPrintExportModal
                card={{
                    ...card,
                    can: {
                        printAnytime: can.printAnytime,
                        exportPng: can.exportPng,
                    },
                }}
                isOpen={exportModal.open}
                initialAction={exportModal.action}
                onClose={() => setExportModal((prev) => ({ ...prev, open: false }))}
            />
        </AuthenticatedLayout>
    );
}
