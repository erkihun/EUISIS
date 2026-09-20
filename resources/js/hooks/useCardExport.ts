import axios from 'axios';
import { toPng } from 'html-to-image';
import { useRef, useState } from 'react';
import { waitForCardAssets } from '@/hooks/useWaitForCardAssets';
import { pixelsForMillimetres, withPngDensity } from '@/utils/pngDensity';

// ── Card dimensions ────────────────────────────────────────────────
// Physical card: ISO/IEC 7810 ID-1 = 85.6 × 54 mm by default, but a template
// may set its own width_mm / height_mm, and that is the size the card must
// print at.
//
// The offscreen capture node stays at ~428 px wide because the card layout is
// designed for that width. The exported pixel count is then derived from the
// card's millimetres at EXPORT_DPI, and the same density is written into the
// PNG, so the file prints at its true physical size instead of at whatever
// the viewer assumes.
export const CARD_W = 428;
export const CARD_H = 270;

/**
 * Print resolution for exported cards. 300 DPI is the standard for card
 * printing: an 85.6 mm card becomes 1011 px, which holds fine detail in the
 * QR and Ethiopic text without producing an unwieldy file.
 */
export const EXPORT_DPI = 300;

/** Capture an unscaled DOM node as a PNG data URL. */
async function captureElement(
    el: HTMLElement,
    opts?: { width?: number; height?: number; widthMm?: number; heightMm?: number; dpi?: number },
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

    // Scale the capture so the output holds exactly the pixels the card's
    // millimetres need at this DPI. Falls back to 2x when a caller has not
    // said how big the card is.
    const dpi = opts?.dpi ?? EXPORT_DPI;
    const pixelRatio = opts?.widthMm
        ? pixelsForMillimetres(opts.widthMm, dpi) / targetW
        : 2;

    const dataUrl = await toPng(el, {
        pixelRatio,
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

    // Stamp the print density so the file carries its physical size.
    return withPngDensity(dataUrl, dpi);
}

function downloadDataUrl(dataUrl: string, fileName: string): void {
    const link = document.createElement('a');
    link.download = fileName;
    link.href = dataUrl;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

export function useCardExport(
    cardId: string,
    cardNumber: string,
    /**
     * The card's real size, from the template. Exports are rendered to the
     * pixel count these millimetres need at `dpi`, and the PNG carries that
     * density so it prints at exactly this size.
     */
    size?: { widthMm: number; heightMm: number; dpi?: number },
) {
    const capture = (el: HTMLElement): Promise<string> => captureElement(el, size);
    const [exporting, setExporting] = useState(false);
    // Capture refs point at the always-rendered offscreen full-size nodes
    // hosted by `CardPrintExportModal`.
    const frontRef = useRef<HTMLDivElement>(null);
    const backRef  = useRef<HTMLDivElement>(null);

    async function auditExport(side: 'front' | 'back' | 'both'): Promise<void> {
        await axios.post(route('id-cards.export.audit', cardId), { side, action: 'export_png' });
    }

    async function exportFront(): Promise<void> {
        if (!frontRef.current) return;
        setExporting(true);
        try {
            await auditExport('front');
            const dataUrl = await capture(frontRef.current);
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
            await auditExport('back');
            const dataUrl = await capture(backRef.current);
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
            await auditExport('both');
            const frontUrl = await capture(frontRef.current);
            downloadDataUrl(frontUrl, `id-card-${cardNumber}-front.png`);
            const backUrl  = await capture(backRef.current);
            downloadDataUrl(backUrl,  `id-card-${cardNumber}-back.png`);
        } catch (e) {
            console.error('Export failed', e);
        } finally {
            setExporting(false);
        }
    }

    return {
        // Capture targets (offscreen, full-size) — used by exportFront/Back/Both.
        frontRef,
        backRef,
        exporting,
        exportFront,
        exportBack,
        exportBoth,
    };
}
