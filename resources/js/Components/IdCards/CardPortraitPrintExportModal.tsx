import { mapCardEmployee } from '@/Components/IdCards/mapCardEmployee';
import { useCardDimensions } from '@/Components/IdCards/IdCardTemplateContext';
import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toPng } from 'html-to-image';
import { EXPORT_DPI } from '@/hooks/useCardExport';
import { pixelsForMillimetres, withPngDensity } from '@/utils/pngDensity';
import axios from 'axios';
import IdCardPortraitFront from '@/Components/IdCards/IdCardPortraitFront';
import IdCardPortraitBack from '@/Components/IdCards/IdCardPortraitBack';
import { waitForCardAssets } from '@/hooks/useWaitForCardAssets';
import { useLocale } from '@/hooks/useLocale';
import type { CardForExport } from '@/Components/IdCards/CardPrintExportModal';

// Portrait card: 54 × 85.6 mm = 540 × 856 px at 10 px/mm.
// Capture at half size (270 × 428) then pixelRatio:2 → 540 × 856 output.

type Tab = 'front' | 'back' | 'both';
type PrintSide = 'front' | 'back' | 'both' | null;

type Props = {
    card: CardForExport;
    isOpen: boolean;
    onClose: () => void;
};

async function capturePortrait(el: HTMLElement, widthMm: number, dpi = EXPORT_DPI): Promise<string> {
    await waitForCardAssets(el);
    // Exported at the pixel count the card's millimetres need, and stamped
    // with that density, so the PNG prints at its true physical size.
    const dataUrl = await toPng(el, {
        pixelRatio: pixelsForMillimetres(widthMm, dpi) / el.offsetWidth,
        backgroundColor: '#ffffff',
        width: el.offsetWidth,
        height: el.offsetHeight,
        skipFonts: true,
        style: { transform: 'none', transformOrigin: 'top left', margin: '0', padding: '0' },
    });

    return withPngDensity(dataUrl, dpi);
}

function downloadDataUrl(dataUrl: string, fileName: string): void {
    const a = document.createElement('a');
    a.download = fileName;
    a.href = dataUrl;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

export default function CardPortraitPrintExportModal({ card, isOpen, onClose }: Props) {
    const { t, locale } = useLocale();
    const { width: PORTRAIT_W, height: PORTRAIT_H, widthMm: portraitWidthMm, printStyle } = useCardDimensions('portrait');
    const [tab, setTab]           = useState<Tab>('front');
    const [exporting, setExporting] = useState(false);

    const frontRef      = useRef<HTMLDivElement>(null);
    const backRef       = useRef<HTMLDivElement>(null);

    if (!isOpen) return null;

    const fmtDate = (v?: string | null) =>
        v ? new Date(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : undefined;

    const qrValue = card.feedback_qr_url ?? null;
    const canExport = card.can.exportPng    === true;

    async function audit(side: Tab) {
        await axios.post(route('id-cards.export.audit', card.id), { side, action: 'export_png' });
    }

    async function handleExport() {
        setExporting(true);
        try {
            await audit(tab);
            if (tab === 'front' || tab === 'both') {
                if (frontRef.current) {
                    const url = await capturePortrait(frontRef.current, portraitWidthMm);
                    downloadDataUrl(url, `id-card-${card.card_number}-portrait-front.png`);
                }
            }
            if (tab === 'back' || tab === 'both') {
                if (backRef.current) {
                    const url = await capturePortrait(backRef.current, portraitWidthMm);
                    downloadDataUrl(url, `id-card-${card.card_number}-portrait-back.png`);
                }
            }
        } catch (e) {
            console.error('Portrait export failed', e);
        } finally {
            setExporting(false);
        }
    }

    // Same card data as the landscape export modal — the printed portrait card
    // must carry identical, locale-aware employee information.
    const frontProps = {
        ...mapCardEmployee(card),
        employeeNumber: card.employee?.employee_number,
        organizationName: card.employee?.current_assignment?.organization?.name_en,
        organizationNameAm: card.employee?.current_assignment?.organization?.name_am,
        organizationUnitName: locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am : card.employee?.current_assignment?.organization_unit?.name_en,
        organizationLogoUrl: card.employee?.current_assignment?.organization?.logo_url,
        positionTitle: card.employee?.current_assignment?.position?.title_en,
        positionTitleAm: card.employee?.current_assignment?.position?.title_am,
        positionCode: card.employee?.current_assignment?.position?.job_position_code,
        jobGrade: card.employee?.current_assignment?.position?.grade_level,
        issueDate: fmtDate(card.issued_at),
        expiryDate: fmtDate(card.expires_at),
        status: card.status,
    };

    const exportLabel = tab === 'front' ? t('idCards.exportFront') : tab === 'back' ? t('idCards.exportBack') : t('idCards.exportBoth');

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
                        <IdCardPortraitBack cardNumber={card.card_number} qrValue={qrValue} emergencyContactName={card.employee?.emergency_contact_name} emergencyContactPhone={card.employee?.emergency_contact_phone} photoUrl={card.employee?.photo_url} rootStyle={{ width: '100%', height: '100%', maxWidth: 'none' }} />
                    </div>
                </div>,
                document.body,
            )}

            {/* ── Modal overlay ──────────────────────────────────────────── */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm no-print"
                onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
            >
                <div className="w-full max-w-lg rounded-panel bg-white shadow-2xl dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700">

                    {/* Header */}
                    <div className="flex items-center justify-between px-6 pt-5 pb-3">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                                {t('idCards.exportPng')}
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
                    <div className="mx-6 mb-4 flex gap-1 rounded-card border border-gray-200 bg-gray-50 p-1 dark:border-slate-700 dark:bg-slate-800">
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
                            <IdCardPortraitBack cardNumber={card.card_number} qrValue={qrValue} emergencyContactName={card.employee?.emergency_contact_name} emergencyContactPhone={card.employee?.emergency_contact_phone} photoUrl={card.employee?.photo_url} />
                        )}
                    </div>

                    {/* Footer */}
                    <div className="flex justify-end gap-2 rounded-b-panel border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            {t('common.close')}
                        </button>
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
