import axios from 'axios';
import { toPng } from 'html-to-image';
import { useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { waitForCardAssets } from '@/hooks/useWaitForCardAssets';

// ── Card dimensions ────────────────────────────────────────────────
// Physical card: ISO/IEC 7810 ID-1 = 85.6 × 54 mm.
//
// We render the offscreen capture node at 428 × 270 px (half the
// target output) so the card layout — designed for ~400 px wide —
// looks correct. html-to-image then doubles it via pixelRatio:2 to
// produce a 856 × 540 px PNG (ISO/IEC 7810 ID-1 at ~254 DPI).
export const CARD_W = 428;
export const CARD_H = 270;

export type PrintSide = 'front' | 'back' | 'both' | null;

/** Capture an unscaled DOM node as a PNG data URL. */
async function captureElement(
    el: HTMLElement,
    opts?: { width?: number; height?: number },
): Promise<string> {
    // Make sure all images & web fonts have finished loading before
    // html-to-image walks the DOM — otherwise the photo, org logo or
    // Ethiopic text are missing from the capture.
    await waitForCardAssets(el);

    const targetW = opts?.width ?? el.offsetWidth;
    const targetH = opts?.height ?? el.offsetHeight;

    if (import.meta.env.DEV) {
        const rect = el.getBoundingClientRect();
        console.debug(
            '[card-export] el.getBoundingClientRect():',
            Math.round(rect.width), '×', Math.round(rect.height),
            'at', Math.round(rect.left), ',', Math.round(rect.top),
            '| target:', targetW, '×', targetH,
        );
        if (Math.abs(rect.width - targetW) > 4 || Math.abs(rect.height - targetH) > 4) {
            console.warn('[card-export] element size mismatch — PNG may be wrong');
        }
    }

    return await toPng(el, {
        pixelRatio: 2,
        backgroundColor: '#ffffff',
        width: targetW,
        height: targetH,
        // Cross-origin stylesheets (fonts.bunny.net, Google Fonts) throw a
        // SecurityError when html-to-image tries to read their cssRules.
        // skipFonts bypasses that step; the browser uses its already-loaded
        // font cache when rendering the SVG foreignObject to canvas.
        skipFonts: true,
        style: {
            transform: 'none',
            transformOrigin: 'top left',
            margin: '0',
            padding: '0',
        },
    });
}

function downloadDataUrl(dataUrl: string, fileName: string): void {
    const link = document.createElement('a');
    link.download = fileName;
    link.href = dataUrl;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

export function useCardExport(cardId: string, cardNumber: string) {
    const [exporting, setExporting] = useState(false);
    // Capture refs point at the always-rendered offscreen full-size nodes
    // hosted by `CardPrintExportModal`.
    const frontRef = useRef<HTMLDivElement>(null);
    const backRef  = useRef<HTMLDivElement>(null);
    // Print area refs point at full-size nodes that become visible via the
    // `@media print` CSS when `window.print()` is called.
    const printFrontRef = useRef<HTMLDivElement>(null);
    const printBackRef  = useRef<HTMLDivElement>(null);
    // Toggles which side is rendered into the print area before printing.
    const [printSide, setPrintSide] = useState<PrintSide>(null);

    async function auditExport(
        side: 'front' | 'back' | 'both',
        action: 'print' | 'export_png' = 'export_png',
    ): Promise<void> {
        await axios.post(route('id-cards.export.audit', cardId), { side, action });
    }

    async function exportFront(): Promise<void> {
        if (!frontRef.current) return;
        setExporting(true);
        try {
            await auditExport('front', 'export_png');
            const dataUrl = await captureElement(frontRef.current);
            downloadDataUrl(dataUrl, `id-card-${cardNumber}-front.png`);
        } catch (e) {
            console.error('Export failed', e);
        } finally {
            setExporting(false);
        }
    }

    async function exportBack(): Promise<void> {
        if (!backRef.current) return;
        setExporting(true);
        try {
            await auditExport('back', 'export_png');
            const dataUrl = await captureElement(backRef.current);
            downloadDataUrl(dataUrl, `id-card-${cardNumber}-back.png`);
        } catch (e) {
            console.error('Export failed', e);
        } finally {
            setExporting(false);
        }
    }

    /**
     * Export both sides — emits TWO separate PNG downloads (front, then back).
     * Simpler than stitching them server-side and lets the user place them
     * on a duplex printer.
     */
    async function exportBoth(): Promise<void> {
        if (!frontRef.current || !backRef.current) return;
        setExporting(true);
        try {
            await auditExport('both', 'export_png');
            const frontUrl = await captureElement(frontRef.current);
            downloadDataUrl(frontUrl, `id-card-${cardNumber}-front.png`);
            const backUrl  = await captureElement(backRef.current);
            downloadDataUrl(backUrl,  `id-card-${cardNumber}-back.png`);
        } catch (e) {
            console.error('Export failed', e);
        } finally {
            setExporting(false);
        }
    }

    async function printCard(side: 'front' | 'back' | 'both'): Promise<void> {
        setExporting(true);
        try {
            await auditExport(side, 'print');

            // Mount the card(s) into the print area synchronously so the ref
            // is populated before we try to access it. Without flushSync,
            // setPrintSide is batched and the ref is still null when we
            // check it — causing an early return and nothing being printed.
            flushSync(() => setPrintSide(side));

            // Now the print area is mounted and the ref is non-null.
            if (side === 'front' && printFrontRef.current) {
                await waitForCardAssets(printFrontRef.current);
            } else if (side === 'back' && printBackRef.current) {
                await waitForCardAssets(printBackRef.current);
            } else if (side === 'both') {
                if (printFrontRef.current) await waitForCardAssets(printFrontRef.current);
                if (printBackRef.current)  await waitForCardAssets(printBackRef.current);
            }

            window.print();
        } catch (e) {
            console.error('Print failed', e);
        } finally {
            setPrintSide(null);
            setExporting(false);
        }
    }

    return {
        // Capture targets (offscreen, full-size) — used by exportFront/Back/Both.
        frontRef,
        backRef,
        // Print targets (inside `.id-card-print-area`, full-size) — used by printCard.
        printFrontRef,
        printBackRef,
        // Which side, if any, is currently mounted into the print area.
        printSide,
        exporting,
        exportFront,
        exportBack,
        exportBoth,
        printCard,
    };
}
