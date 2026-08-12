import { useState } from 'react';
import { createPortal } from 'react-dom';
import IdCardFront from '@/Components/IdCards/IdCardFront';
import IdCardBack from '@/Components/IdCards/IdCardBack';
import { CARD_CANVAS_HEIGHT, CARD_CANVAS_WIDTH } from '@/Components/IdCards/IdCardCanvas';
import { useLocale } from '@/hooks/useLocale';
import { useCardExport } from '@/hooks/useCardExport';

export type CardForExport = {
    id: string;
    card_number: string;
    status: string;
    issued_at?: string | null;
    expires_at?: string | null;
    qr_payload?: string | null;
    public_card_uuid?: string | null;
    can: {
        printAnytime?: boolean;
        exportPng?: boolean;
        previewSvg?: boolean;
    };
    employee?: {
        full_name?: string | null;
        metadata?: { name_en?: string | null; name_am?: string | null } | null;
        name_en?: string | null;
        gender?: string | null;
        status?: string | null;
        employee_number?: string | null;
        photo_url?: string | null;
        current_assignment?: {
            organization?: { name_en?: string | null; name_am?: string | null; logo_url?: string | null } | null;
            organization_unit?: { name_en?: string | null; name_am?: string | null } | null;
            position?: { title_en?: string | null; title_am?: string | null; job_position_code?: string | null; grade_level?: string | null } | null;
        } | null;
    } | null;
};

type Tab = 'front' | 'back' | 'both';

type Props = {
    card: CardForExport;
    isOpen: boolean;
    onClose: () => void;
    /** When true the modal opens already on the "print" workflow rather than export */
    initialAction?: 'print' | 'export_png';
};

// Capture element rendered at half the output size so the card layout
// (designed for ~400 px wide) looks correct. pixelRatio:2 in useCardExport
// doubles it to produce a 856×540 px PNG.
const CARD_W = CARD_CANVAS_WIDTH / 2;   // 428 px
const CARD_H = CARD_CANVAS_HEIGHT / 2;  // 270 px

export default function CardPrintExportModal({ card, isOpen, onClose, initialAction = 'export_png' }: Props) {
    const { t, locale } = useLocale();
    const [tab, setTab] = useState<Tab>('front');

    const {
        frontRef,
        backRef,
        printFrontRef,
        printBackRef,
        printSide,
        exporting,
        exportFront,
        exportBack,
        exportBoth,
        printCard,
    } = useCardExport(card.id, card.card_number);

    if (!isOpen) return null;

    const fmtDate = (v?: string | null) =>
        v ? new Date(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : undefined;

    const qrValue = card.public_card_uuid ? route('id-cards.verify.public', card.public_card_uuid) : null;

    const canPrint      = card.can.printAnytime === true;
    const canExport     = card.can.exportPng    === true;
    const canPreviewSvg = card.can.previewSvg   === true;

    // Server-rendered SVG preview URLs — browser sends session cookie automatically.
    const svgFrontUrl = canPreviewSvg ? route('id-cards.preview.svg.front', card.id) : null;
    const svgBackUrl  = canPreviewSvg ? route('id-cards.preview.svg.back',  card.id) : null;

    async function handleExport() {
        if (tab === 'front')     await exportFront();
        else if (tab === 'back') await exportBack();
        else                     await exportBoth();
    }

    async function handlePrint() {
        await printCard(tab);
    }

    // Shared card props used by the offscreen capture portal and print portal.
    const frontProps = {
        cardNumber: card.card_number,
        fullName: card.employee?.name_en ?? card.employee?.metadata?.name_en ?? card.employee?.full_name,
        fullNameAm: card.employee?.metadata?.name_am ?? card.employee?.full_name,
        employeeNumber: card.employee?.employee_number,
        organizationName: card.employee?.current_assignment?.organization?.name_en,
        organizationNameAm: card.employee?.current_assignment?.organization?.name_am,
        organizationUnitName: locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am : card.employee?.current_assignment?.organization_unit?.name_en,
        organizationLogoUrl: card.employee?.current_assignment?.organization?.logo_url,
        positionTitle: card.employee?.current_assignment?.position?.title_en,
        positionTitleAm: card.employee?.current_assignment?.position?.title_am,
        positionCode: card.employee?.current_assignment?.position?.job_position_code,
        jobGrade: card.employee?.current_assignment?.position?.grade_level,
        employmentStatus: card.employee?.status,
        gender: card.employee?.gender,
        photoUrl: card.employee?.photo_url,
        issueDate: fmtDate(card.issued_at),
        expiryDate: fmtDate(card.expires_at),
        status: card.status,
    };

    const exportLabel =
        tab === 'front' ? t('idCards.exportFront')
      : tab === 'back'  ? t('idCards.exportBack')
      :                   t('idCards.exportBoth');

    const printLabel =
        tab === 'front' ? t('idCards.printFront')
      : tab === 'back'  ? t('idCards.printBack')
      :                   t('idCards.printBoth');

    return (
        <>
            {/* ── Offscreen capture portal ────────────────────────────────
                Rendered at fixed (0,0) so html-to-image gets a non-negative
                bounding rect. clip-path hides it visually without affecting
                the clone html-to-image starts at frontRef / backRef.
                Cards are rendered at CARD_W×CARD_H (428×270 px) — the layout
                was designed for ~400 px wide, so this size looks correct.
                pixelRatio:2 in captureElement doubles the output to 856×540. */}
            {createPortal(
                <div
                    aria-hidden="true"
                    className="no-print"
                    style={{
                        position: 'fixed',
                        top: 0,
                        left: 0,
                        width: CARD_W,
                        height: CARD_H * 2 + 32,
                        clipPath: 'inset(0 0 0 100%)',
                        pointerEvents: 'none',
                        zIndex: 49,
                    }}
                >
                    <div ref={frontRef} style={{ width: CARD_W, height: CARD_H }}>
                        <IdCardFront {...frontProps} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                    </div>
                    <div style={{ height: 32 }} />
                    <div ref={backRef} style={{ width: CARD_W, height: CARD_H }}>
                        <IdCardBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                    </div>
                </div>,
                document.body,
            )}

            {/* ── Print portal ───────────────────────────────────────────
                Hidden on screen, made visible by `@media print` rules in
                app.css. Mounts only the side(s) being printed.
                Important: do NOT use IdCardCanvas here — its 856×540 inline
                styles would overflow the 85.6mm×54mm CSS container and the
                browser would clip instead of scale the card. Plain divs let
                the card components fill the physical container naturally. */}
            {createPortal(
                <div className="id-card-print-area" aria-hidden={printSide === null}>
                    {(printSide === 'front' || printSide === 'both') && (
                        <div className="id-card-print-card" style={{ borderRadius: 0 }}>
                            <div ref={printFrontRef} style={{ width: '100%', height: '100%' }}>
                                <IdCardFront {...frontProps} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                            </div>
                        </div>
                    )}
                    {printSide === 'both' && <div className="id-card-print-spacer" />}
                    {(printSide === 'back' || printSide === 'both') && (
                        <div className="id-card-print-card" style={{ borderRadius: 0 }}>
                            <div ref={printBackRef} style={{ width: '100%', height: '100%' }}>
                                <IdCardBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                            </div>
                        </div>
                    )}
                </div>,
                document.body,
            )}

            {/* Modal overlay */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm no-print"
                onMouseDown={(e) => {
                    if (e.target === e.currentTarget) onClose();
                }}
            >
                <div className="w-full max-w-2xl rounded-2xl bg-white shadow-2xl dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700">
                    {/* Header */}
                    <div className="flex items-center justify-between px-6 pt-5 pb-3">
                        <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                            {initialAction === 'print' ? t('idCards.printCard') : t('idCards.exportPng')}
                        </h3>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Notice: no status change */}
                    <p className="mx-6 mb-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:border-amber-700 dark:text-amber-400">
                        {t('idCards.thisActionDoesNotChangeStatus')}
                    </p>

                    {/* Tabs */}
                    <div className="mx-6 mb-4 flex gap-1 rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                        {(['front', 'back', 'both'] as Tab[]).map((value) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setTab(value)}
                                className={`flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                                    tab === value
                                        ? 'bg-white shadow text-gray-900 dark:bg-slate-700 dark:text-slate-100'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'
                                }`}
                            >
                                {value === 'front' ? t('idCards.cardFront')
                                  : value === 'back' ? t('idCards.cardBack')
                                  : t('idCards.frontSide') + ' + ' + t('idCards.backSide')}
                            </button>
                        ))}
                    </div>

                    {/* Visible preview */}
                    <div className="mx-6 mb-4 flex flex-wrap justify-center gap-3">
                        {canPreviewSvg ? (
                            // Server-rendered SVG — shows the authoritative card design.
                            <>
                                {(tab === 'front' || tab === 'both') && svgFrontUrl && (
                                    <img
                                        key={`svg-front-${card.id}`}
                                        src={svgFrontUrl}
                                        alt={t('idCards.cardFront')}
                                        style={{ maxWidth: '100%', height: 'auto', borderRadius: 8 }}
                                    />
                                )}
                                {(tab === 'back' || tab === 'both') && svgBackUrl && (
                                    <img
                                        key={`svg-back-${card.id}`}
                                        src={svgBackUrl}
                                        alt={t('idCards.cardBack')}
                                        style={{ maxWidth: '100%', height: 'auto', borderRadius: 8 }}
                                    />
                                )}
                            </>
                        ) : (
                            // Fallback: React component preview.
                            <>
                                {(tab === 'front' || tab === 'both') && (
                                    <IdCardFront {...frontProps} />
                                )}
                                {(tab === 'back' || tab === 'both') && (
                                    <IdCardBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} />
                                )}
                            </>
                        )}
                    </div>

                    {/* Footer actions */}
                    <div className="flex justify-end gap-2 rounded-b-2xl border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            {t('common.close')}
                        </button>
                        {canPrint && (
                            <button
                                type="button"
                                onClick={handlePrint}
                                disabled={exporting}
                                className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5z" />
                                </svg>
                                {exporting ? t('idCards.printingCard') : printLabel}
                            </button>
                        )}
                        {canExport && (
                            <button
                                type="button"
                                onClick={handleExport}
                                disabled={exporting}
                                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                            >
                                {exporting ? (
                                    <>
                                        <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                        </svg>
                                        {t('idCards.exportingPng')}
                                    </>
                                ) : (
                                    <>
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                        </svg>
                                        {exportLabel}
                                    </>
                                )}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
