import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toPng } from 'html-to-image';
import { flushSync } from 'react-dom';
import axios from 'axios';
import IdCardPortraitFront from '@/Components/IdCards/IdCardPortraitFront';
import IdCardPortraitBack from '@/Components/IdCards/IdCardPortraitBack';
import { waitForCardAssets } from '@/hooks/useWaitForCardAssets';
import { useLocale } from '@/hooks/useLocale';
import type { CardForExport } from '@/Components/IdCards/CardPrintExportModal';

// Portrait card: 54 × 85.6 mm = 540 × 856 px at 10 px/mm.
// Capture at half size (270 × 428) then pixelRatio:2 → 540 × 856 output.
const PORTRAIT_W = 270;
const PORTRAIT_H = 428;

type Tab = 'front' | 'back' | 'both';
type PrintSide = 'front' | 'back' | 'both' | null;

type Props = {
    card: CardForExport;
    isOpen: boolean;
    onClose: () => void;
    initialAction?: 'print' | 'export_png';
};

async function capturePortrait(el: HTMLElement): Promise<string> {
    await waitForCardAssets(el);
    return toPng(el, {
        pixelRatio: 2,
        backgroundColor: '#ffffff',
        width: PORTRAIT_W,
        height: PORTRAIT_H,
        skipFonts: true,
        style: { transform: 'none', transformOrigin: 'top left', margin: '0', padding: '0' },
    });
}

function downloadDataUrl(dataUrl: string, fileName: string): void {
    const a = document.createElement('a');
    a.download = fileName;
    a.href = dataUrl;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

export default function CardPortraitPrintExportModal({ card, isOpen, onClose, initialAction = 'export_png' }: Props) {
    const { t, locale } = useLocale();
    const [tab, setTab]           = useState<Tab>('front');
    const [exporting, setExporting] = useState(false);
    const [printSide, setPrintSide] = useState<PrintSide>(null);

    const frontRef      = useRef<HTMLDivElement>(null);
    const backRef       = useRef<HTMLDivElement>(null);
    const printFrontRef = useRef<HTMLDivElement>(null);
    const printBackRef  = useRef<HTMLDivElement>(null);

    if (!isOpen) return null;

    const fmtDate = (v?: string | null) =>
        v ? new Date(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : undefined;

    const qrValue = card.public_card_uuid ? route('id-checker.show', card.public_card_uuid) : null;
    const canPrint  = card.can.printAnytime === true;
    const canExport = card.can.exportPng    === true;

    async function audit(side: Tab, action: 'print' | 'export_png') {
        await axios.post(route('id-cards.export.audit', card.id), { side, action });
    }

    async function handleExport() {
        setExporting(true);
        try {
            await audit(tab, 'export_png');
            if (tab === 'front' || tab === 'both') {
                if (frontRef.current) {
                    const url = await capturePortrait(frontRef.current);
                    downloadDataUrl(url, `id-card-${card.card_number}-portrait-front.png`);
                }
            }
            if (tab === 'back' || tab === 'both') {
                if (backRef.current) {
                    const url = await capturePortrait(backRef.current);
                    downloadDataUrl(url, `id-card-${card.card_number}-portrait-back.png`);
                }
            }
        } catch (e) {
            console.error('Portrait export failed', e);
        } finally {
            setExporting(false);
        }
    }

    async function handlePrint() {
        setExporting(true);
        try {
            await audit(tab, 'print');
            flushSync(() => setPrintSide(tab));
            if (tab === 'front' && printFrontRef.current) await waitForCardAssets(printFrontRef.current);
            else if (tab === 'back' && printBackRef.current) await waitForCardAssets(printBackRef.current);
            else if (tab === 'both') {
                if (printFrontRef.current) await waitForCardAssets(printFrontRef.current);
                if (printBackRef.current)  await waitForCardAssets(printBackRef.current);
            }
            window.print();
        } catch (e) {
            console.error('Portrait print failed', e);
        } finally {
            setPrintSide(null);
            setExporting(false);
        }
    }

    // Same card data as the landscape export modal — the printed portrait card
    // must carry identical, locale-aware employee information.
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

    const exportLabel = tab === 'front' ? t('idCards.exportFront') : tab === 'back' ? t('idCards.exportBack') : t('idCards.exportBoth');
    const printLabel  = tab === 'front' ? t('idCards.printFront')  : tab === 'back' ? t('idCards.printBack')  : t('idCards.printBoth');

    return (
        <>
            {/* ── Offscreen capture portal ───────────────────────────────── */}
            {createPortal(
                <div
                    aria-hidden="true"
                    className="no-print"
                    style={{
                        position: 'fixed', top: 0, left: 0,
                        width: PORTRAIT_W, height: PORTRAIT_H * 2 + 32,
                        clipPath: 'inset(0 0 0 100%)',
                        pointerEvents: 'none', zIndex: 49,
                    }}
                >
                    <div ref={frontRef} style={{ width: PORTRAIT_W, height: PORTRAIT_H }}>
                        <IdCardPortraitFront {...frontProps} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                    </div>
                    <div style={{ height: 32 }} />
                    <div ref={backRef} style={{ width: PORTRAIT_W, height: PORTRAIT_H }}>
                        <IdCardPortraitBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                    </div>
                </div>,
                document.body,
            )}

            {/* ── Print portal ───────────────────────────────────────────── */}
            {createPortal(
                <div className="id-card-print-area" aria-hidden={printSide === null}>
                    {(printSide === 'front' || printSide === 'both') && (
                        <div className="id-card-print-card-portrait" style={{ borderRadius: 0 }}>
                            <div ref={printFrontRef} style={{ width: '100%', height: '100%' }}>
                                <IdCardPortraitFront {...frontProps} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                            </div>
                        </div>
                    )}
                    {printSide === 'both' && <div className="id-card-print-spacer" />}
                    {(printSide === 'back' || printSide === 'both') && (
                        <div className="id-card-print-card-portrait" style={{ borderRadius: 0 }}>
                            <div ref={printBackRef} style={{ width: '100%', height: '100%' }}>
                                <IdCardPortraitBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                            </div>
                        </div>
                    )}
                </div>,
                document.body,
            )}

            {/* ── Modal overlay ──────────────────────────────────────────── */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm no-print"
                onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
            >
                <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700">

                    {/* Header */}
                    <div className="flex items-center justify-between px-6 pt-5 pb-3">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                                {initialAction === 'print' ? t('idCards.printCard') : t('idCards.exportPng')}
                            </h3>
                            <p className="text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">Portrait design</p>
                        </div>
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

                    {/* Notice */}
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

                    {/* Preview */}
                    <div className="mx-6 mb-4 flex flex-wrap justify-center gap-4">
                        {(tab === 'front' || tab === 'both') && (
                            <IdCardPortraitFront {...frontProps} />
                        )}
                        {(tab === 'back' || tab === 'both') && (
                            <IdCardPortraitBack cardNumber={card.card_number} qrValue={qrValue} issueDate={fmtDate(card.issued_at)} expiryDate={fmtDate(card.expires_at)} />
                        )}
                    </div>

                    {/* Footer */}
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
