import type { CaseDetailCase, CaseDocument, CaseWitness } from '@/court/types/caseDetail';
import { restoreLostSpaces } from './DocumentSheet';

export interface PrintLabels {
    title: string;
    caseNumber: string;
    filingDate: string;
    applicant: string;
    submittedByLawyer: string | null;
    name: string;
    address: string;
    email: string;
    respondent: string;
    details: string;
    noDetails: string;
    relief: string;
    noRelief: string;
    documents: string;
    document: string;
    witnesses: string;
    phone: string;
    printedAt: string;
}

export interface PrintData {
    kase: CaseDetailCase;
    filingDate: string;
    docs: Array<CaseDocument & { created_display: string | null }>;
    witnesses: CaseWitness[];
    termsItems: string[];
    locale: string;
}

const escape = (value: string | null | undefined): string =>
    String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const dash = (value: string | null | undefined): string => {
    const text = (value ?? '').trim();
    return text === '' ? '—' : escape(text);
};

/** A party's block: its role over a name / address (/ email) list. */
const party = (heading: string, rows: Array<[string, string | null | undefined]>, note: string | null = null): string => `
<div class="doc-party">
    <h3>${escape(heading)}</h3>
    ${note ? `<p class="doc-party-note">${escape(note)}</p>` : ''}
    <table>${rows.map(([label, value]) => `<tr><th>${escape(label)}</th><td>${dash(value)}</td></tr>`).join('')}</table>
</div>`;

/**
 * The printable case overview, opened in its own window and closed again
 * after printing: an A4 page in the court's Ethiopic document face (the same
 * file the app serves, so a machine without it installed prints the same),
 * the case number and filing date under the title, the two parties side by
 * side, the claim and the relief justified as filed, then the documents and
 * the witnesses, with the page number at the foot of every page. Returns
 * false when the browser blocked the window.
 */
export function printCaseOverview(labels: PrintLabels, data: PrintData): boolean {
    const { kase } = data;
    const fontUrl = `${window.location.origin}/fonts/AbyssinicaSIL-Regular.ttf`;
    const body = `
<header class="doc-head">
    <h1>${escape(labels.title)}</h1>
    <table class="doc-meta">
        <tr><th>${escape(labels.caseNumber)}</th><td>${escape(kase.case_number)}</td><th>${escape(labels.filingDate)}</th><td>${escape(data.filingDate)}</td></tr>
    </table>
</header>
<section class="doc-parties">
    ${party(labels.applicant, [[labels.name, kase.applicant_display_name], [labels.address, kase.applicant_address], [labels.email, kase.applicant_email]], labels.submittedByLawyer)}
    ${party(labels.respondent, [[labels.name, kase.respondent_name], [labels.address, kase.respondent_address]])}
</section>
${data.termsItems.length > 0 ? `<ol class="doc-terms">${data.termsItems.map((item) => `<li>${item}</li>`).join('')}</ol>` : ''}
<section>
    <h2>${escape(labels.details)}</h2>
    <div class="doc-body">${restoreLostSpaces(kase.description_html) || `<p class="doc-empty">${escape(labels.noDetails)}</p>`}</div>
</section>
<section>
    <h2>${escape(labels.relief)}</h2>
    <div class="doc-body">${kase.relief_html ? restoreLostSpaces(kase.relief_html) : `<p class="doc-empty">${escape(labels.noRelief)}</p>`}</div>
</section>
${data.docs.length > 0 ? `<section><h2>${escape(labels.documents)}</h2><table class="doc-table"><thead><tr><th class="doc-num">#</th><th>${escape(labels.document)}</th><th class="doc-when"></th></tr></thead><tbody>${data.docs.map((doc, i) => `<tr><td class="doc-num">${i + 1}</td><td>${dash(doc.title) === '—' ? escape(labels.document) : escape(doc.title)}</td><td class="doc-when">${escape(doc.created_display)}</td></tr>`).join('')}</tbody></table></section>` : ''}
${data.witnesses.length > 0 ? `<section><h2>${escape(labels.witnesses)}</h2><table class="doc-table"><thead><tr><th class="doc-num">#</th><th>${escape(labels.name)}</th><th>${escape(labels.phone)}</th><th>${escape(labels.email)}</th><th>${escape(labels.address)}</th></tr></thead><tbody>${data.witnesses.map((w, i) => `<tr><td class="doc-num">${i + 1}</td><td>${escape(w.full_name)}</td><td>${dash(w.phone)}</td><td>${dash(w.email)}</td><td>${dash(w.address)}</td></tr>`).join('')}</tbody></table></section>` : ''}
<p class="doc-printed">${escape(labels.printedAt)}</p>`;

    const w = window.open('', '_blank');
    if (!w) return false;

    w.document.write(`<!DOCTYPE html>
<html lang="${escape(data.locale.replace('_', '-'))}">
<head>
<meta charset="utf-8">
<title>${escape(labels.title)} - ${escape(kase.case_number)}</title>
<style>
    @font-face { font-family: 'Court Document'; src: url('${fontUrl}') format('truetype'); font-weight: 400; unicode-range: U+1200-139F, U+2D80-2DDF, U+AB00-AB2F; }
    @page {
        size: A4;
        margin: 2cm 2.2cm 2.2cm;
        @bottom-center { content: counter(page) " / " counter(pages); font: 9pt 'Times New Roman', serif; color: #444; }
        @bottom-right { content: "${escape(kase.case_number).replace(/"/g, '')}"; font: 9pt 'Court Document', 'Times New Roman', serif; color: #444; }
    }
    * { box-sizing: border-box; }
    body { font-family: 'Court Document', 'Abyssinica SIL', 'Nyala', 'Noto Sans Ethiopic', 'Times New Roman', serif; font-size: 12pt; line-height: 1.75; color: #000; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .doc-head { text-align: center; margin: 0 0 14pt; }
    h1 { font-size: 16pt; letter-spacing: 0.02em; margin: 0 0 8pt; }
    .doc-meta { margin: 0 auto; border-collapse: collapse; font-size: 11pt; }
    .doc-meta th, .doc-meta td { border: 1px solid #000; padding: 3pt 9pt; }
    .doc-meta th { font-weight: normal; background: #f1f1f1; }
    .doc-meta td { font-weight: bold; }
    .doc-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin: 0 0 12pt; break-inside: avoid; }
    .doc-party { border: 1px solid #000; padding: 6pt 9pt; }
    .doc-party h3 { font-size: 11.5pt; margin: 0 0 4pt; padding-bottom: 3pt; border-bottom: 1px solid #999; }
    .doc-party-note { margin: 0 0 4pt; font-size: 10.5pt; font-style: italic; }
    .doc-party table { border-collapse: collapse; width: 100%; font-size: 11pt; line-height: 1.5; }
    .doc-party th { width: 32%; padding: 1.5pt 6pt 1.5pt 0; font-weight: normal; color: #333; text-align: left; vertical-align: top; }
    .doc-party td { padding: 1.5pt 0; font-weight: bold; vertical-align: top; white-space: pre-line; overflow-wrap: anywhere; }
    .doc-terms { margin: 0 0 12pt; padding-left: 20pt; text-align: justify; font-size: 11pt; }
    .doc-terms li { margin: 0 0 4pt; }
    section { margin: 0 0 10pt; }
    h2 { font-size: 13pt; margin: 14pt 0 6pt; padding-bottom: 2pt; border-bottom: 1.5px solid #000; break-after: avoid; page-break-after: avoid; }
    .doc-body { text-align: justify; text-justify: inter-word; orphans: 3; widows: 3; overflow-wrap: break-word; }
    .doc-body span, .doc-body font { font-size: inherit !important; line-height: inherit !important; font-family: inherit !important; }
    .doc-body p { margin: 0 0 3pt; }
    .doc-body ol, .doc-body ul { margin: 3pt 0 6pt; padding-left: 20pt; }
    .doc-body li { margin: 0 0 3pt; text-align: justify; }
    .doc-body table { border-collapse: collapse; width: 100%; margin: 4pt 0; }
    .doc-body td, .doc-body th { border: 1px solid #000; padding: 3pt 5pt; vertical-align: top; }
    .doc-empty { font-style: italic; color: #444; }
    .doc-table { border-collapse: collapse; width: 100%; font-size: 11pt; line-height: 1.5; }
    .doc-table th, .doc-table td { border: 1px solid #000; padding: 3pt 6pt; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    .doc-table th { background: #f1f1f1; }
    .doc-table tr { break-inside: avoid; }
    .doc-num { width: 22pt; text-align: center !important; }
    .doc-when { width: 30%; white-space: nowrap; }
    .doc-printed { margin-top: 18pt; text-align: right; font-size: 9.5pt; color: #444; }
</style>
</head>
<body>${body}</body>
</html>`);
    w.document.close();
    w.focus();

    const closePrintWindow = () => {
        setTimeout(() => {
            if (!w.closed) w.close();
        }, 100);
    };
    w.addEventListener('afterprint', closePrintWindow);
    if (w.matchMedia) {
        const mediaQueryList = w.matchMedia('print');
        mediaQueryList.addEventListener('change', (event) => {
            if (!event.matches) closePrintWindow();
        });
    }
    // Print once the document face has loaded (or after 2 s without it), so the first page is not
    // laid out in a fallback font.
    let printed = false;
    const print = () => {
        if (printed || w.closed) return;
        printed = true;
        w.print();
        setTimeout(closePrintWindow, 500);
    };
    void w.document.fonts?.load(`12pt 'Court Document'`, 'ሀ').then(print, print);
    setTimeout(print, 2000);

    return true;
}
