/**
 * Text the React case detail page needs that no lang/{en,am}/*.php key provides.
 * To be merged into court/i18n/{en,am}.ts under `court.caseDetail`; until then
 * Pages/Court/Cases/Show/useCaseText.ts reads it directly as the fallback.
 */
const caseDetail = {
    en: {
        hearing_locked: 'Locked (today/past)',
        hearing_date: 'Hearing date',
        hearing_time: 'Hearing time',
        edit_hearing: 'Edit hearing',
        send_hint: 'Enter to send, Shift+Enter for a new line',
        edit_witness: 'Edit witness',
        remove_witness: 'Remove witness',
        timeline_title: 'Status timeline',
        timeline_empty: 'No status changes recorded yet.',
        timeline_opened: 'Opened as',
        timeline_from: 'From',
        timeline_to: 'To',
        timeline_note: 'Note',
        print_dialog_blocked: 'The browser blocked the print window. Allow pop-ups for this site and try again.',
        template_loaded: 'Template loaded into the letter.',
        open_pdf: 'Open PDF',
        replies_count: 'replies',
        organization_status: 'Status',
        show_full_text: 'Show the full text',
        show_less: 'Show less',
        view_pdf: 'Filed PDF',
        view_text: 'Text',
        open_new_tab: 'Open in a new tab',
        response_number: 'Response no.',
        review_title: 'Review',
    },
    am: {
        hearing_locked: 'ተቆልፏል (ዛሬ/ያለፈ)',
        hearing_date: 'የችሎት ቀን',
        hearing_time: 'የችሎት ሰዓት',
        edit_hearing: 'ችሎት አርትዕ',
        send_hint: 'ለመላክ Enter፣ ለአዲስ መስመር Shift+Enter',
        edit_witness: 'ምስክር አርትዕ',
        remove_witness: 'ምስክር አስወግድ',
        timeline_title: 'የሁኔታ ታሪክ',
        timeline_empty: 'እስካሁን የተመዘገበ የሁኔታ ለውጥ የለም።',
        timeline_opened: 'የተከፈተበት ሁኔታ',
        timeline_from: 'ከ',
        timeline_to: 'ወደ',
        timeline_note: 'ማስታወሻ',
        print_dialog_blocked: 'አሳሹ የህትመት መስኮቱን አግዷል። ለዚህ ጣቢያ ብቅ-ባይ መስኮቶችን ፍቀዱና እንደገና ይሞክሩ።',
        template_loaded: 'አብነቱ ወደ ደብዳቤው ገብቷል።',
        open_pdf: 'PDF ክፈት',
        replies_count: 'ምላሾች',
        organization_status: 'ሁኔታ',
        show_full_text: 'ሙሉ ጽሑፉን አሳይ',
        show_less: 'አሳጥር',
        view_pdf: 'የቀረበው PDF',
        view_text: 'ጽሑፍ',
        open_new_tab: 'በአዲስ ትር ክፈት',
        response_number: 'የመልስ ቁጥር',
        review_title: 'ግምገማ',
    },
} as const;

export type CaseDetailTextKey = keyof typeof caseDetail.en;

export default caseDetail;
