import { toPng } from 'html-to-image';
import { useRef, useState } from 'react';
import { waitForCardAssets } from '@/hooks/useWaitForCardAssets';

/**
 * PNG / PDF export for the organogram chart.
 *
 * Capture settings mirror `useCardExport`: `pixelRatio: 2` for a high-DPI
 * raster and `skipFonts: true` because cross-origin stylesheets throw a
 * SecurityError when html-to-image tries to read their cssRules.
 *
 * The PDF is produced by opening a print window containing the captured
 * image, so no extra PDF dependency is needed — the browser's own
 * "Save as PDF" handles pagination and the user picks the destination.
 */

type ExportKind = 'png' | 'pdf' | null;

/** `organogram-<CODE>-YYYY-MM-DD` — safe for a filename on every OS. */
function buildFileName(organizationCode: string, organizationName: string): string {
    const date = new Date().toISOString().slice(0, 10);
    const slug = `${organizationCode}-${organizationName}`
        .normalize('NFKD')
        .replace(/[^\w\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .slice(0, 60);

    return `organogram-${slug || organizationCode}-${date}`;
}

function downloadDataUrl(dataUrl: string, fileName: string): void {
    const link = document.createElement('a');
    link.download = fileName;
    link.href = dataUrl;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

export function useOrganogramExport(organizationCode: string, organizationName: string) {
    const captureRef = useRef<HTMLDivElement>(null);
    const [exporting, setExporting] = useState<ExportKind>(null);
    const [error, setError] = useState<string | null>(null);

    /**
     * Rasterise the chart. The node is captured at its full scrollWidth /
     * scrollHeight so the whole structure is included, not just the part
     * currently visible in the horizontally scrolling container.
     */
    async function capture(node: HTMLElement): Promise<string> {
        await waitForCardAssets(node);

        return await toPng(node, {
            pixelRatio: 2,
            backgroundColor: '#ffffff',
            width: node.scrollWidth,
            height: node.scrollHeight,
            skipFonts: true,
            style: {
                // Neutralise the zoom transform so the export is always
                // captured at 100%, whatever the on-screen zoom level.
                transform: 'none',
                transformOrigin: 'top left',
                margin: '0',
            },
        });
    }

    async function exportPng(): Promise<void> {
        if (!captureRef.current || exporting !== null) {
            return;
        }

        setExporting('png');
        setError(null);

        try {
            const dataUrl = await capture(captureRef.current);
            downloadDataUrl(dataUrl, `${buildFileName(organizationCode, organizationName)}.png`);
        } catch (exception) {
            console.error('Organogram PNG export failed', exception);
            setError('png');
        } finally {
            setExporting(null);
        }
    }

    /**
     * Open the captured chart in a print window sized to the image, letting
     * the browser produce the PDF. `title` seeds the default filename.
     */
    async function exportPdf(headings: { title: string; subtitle: string; generatedLabel: string }): Promise<void> {
        if (!captureRef.current || exporting !== null) {
            return;
        }

        setExporting('pdf');
        setError(null);

        try {
            const dataUrl = await capture(captureRef.current);
            const printWindow = window.open('', '_blank');

            if (printWindow === null) {
                throw new Error('Popup blocked');
            }

            const generatedAt = new Date().toLocaleString();
            const fileName = buildFileName(organizationCode, organizationName);

            printWindow.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>${fileName}</title>
<style>
  @page { size: A4 landscape; margin: 10mm; }
  body { font-family: system-ui, sans-serif; margin: 0; padding: 12px; color: #0f172a; }
  h1 { font-size: 15px; margin: 0 0 2px; }
  p { font-size: 11px; margin: 0 0 10px; color: #475569; }
  img { width: 100%; height: auto; }
</style>
</head>
<body>
<h1></h1>
<p></p>
<img alt="">
<script>
  window.addEventListener('load', function () { window.focus(); window.print(); });
</script>
</body>
</html>`);

            // Assign text/src via the DOM rather than string interpolation so
            // organization names can never break out of the markup.
            printWindow.document.title = fileName;
            const heading = printWindow.document.querySelector('h1');
            const meta = printWindow.document.querySelector('p');
            const image = printWindow.document.querySelector('img');

            if (heading) heading.textContent = `${headings.title} — ${headings.subtitle}`;
            if (meta) meta.textContent = `${headings.generatedLabel}: ${generatedAt}`;
            if (image) image.setAttribute('src', dataUrl);

            printWindow.document.close();
        } catch (exception) {
            console.error('Organogram PDF export failed', exception);
            setError('pdf');
        } finally {
            setExporting(null);
        }
    }

    return { captureRef, exporting, error, exportPng, exportPdf, clearError: () => setError(null) };
}
