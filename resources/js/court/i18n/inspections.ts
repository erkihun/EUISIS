/**
 * Text the React inspection, bench-note and record pages need that no
 * lang/{en,am}/*.php key provides (the Blade pages hard-coded it in English).
 * To be merged into court/i18n/{en,am}.ts under `court.inspectionPages`; until
 * then court/i18n/useInspectionPagesText.ts reads it directly as the fallback.
 */
const inspectionPages = {
    en: {
        // Bench notes
        note_content: 'Note Content',
        judge_n: 'Judge :n',
        judge_signature: 'Judge :n signature',
        current_judge: 'Current Judge',
        view: 'View',
        back: 'Back',
        filter: 'Filter',
        reset: 'Reset',
        forbidden: 'You do not have permission to view bench notes.',
        // Inspections
        requests_and_findings: 'Requests and findings',
        request_details: 'Request details',
        finding_details: 'Finding details',
        attachment_selected: 'Selected file',
        attachment_keep: 'Leave empty to keep the current attachment.',
        // Records
        record_sections: 'Sections',
        print_record: 'Print / PDF',
        appeal_record: 'Appeal record (PDF)',
        open_case: 'Open case',
        status_unknown: 'n/a',
    },
    am: {
        note_content: 'የማስታወሻ ይዘት',
        judge_n: 'ዳኛ :n',
        judge_signature: 'የዳኛ :n ፊርማ',
        current_judge: 'የአሁኑ ዳኛ',
        view: 'ይመልከቱ',
        back: 'ተመለስ',
        filter: 'አጣራ',
        reset: 'አጽዳ',
        forbidden: 'የችሎት ውሎ ማስታወሻዎችን ለማየት ፈቃድ የለዎትም።',
        requests_and_findings: 'ጥያቄዎች እና ግኝቶች',
        request_details: 'የጥያቄ ዝርዝር',
        finding_details: 'የግኝት ዝርዝር',
        attachment_selected: 'የተመረጠ ፋይል',
        attachment_keep: 'የአሁኑን አባሪ ለማስቀጠል ባዶ ይተዉት።',
        record_sections: 'ክፍሎች',
        print_record: 'አትም / PDF',
        appeal_record: 'የይግባኝ መዝገብ (PDF)',
        open_case: 'ጉዳዩን ክፈት',
        status_unknown: 'የለም',
    },
} as const;

export type InspectionPagesTextKey = keyof typeof inspectionPages.en;

export default inspectionPages;
