/**
 * Text the React decision pages need that no lang/{en,am}/*.php key provides.
 * To be merged into court/i18n/{en,am}.ts under `court.decisionForms`; until
 * then Pages/Court/Decisions/useDecisionText.ts reads it directly as the fallback.
 */
const decisionForms = {
    en: {
        case_prefix: 'Case',
        header_preview: 'Header preview',
        footer_preview: 'Footer preview',
        output_new_tab: 'The PDF opens in a new tab.',
        no_case_selected: 'Select a case to fill in the parties.',
        review_delete_title: 'Delete review',
        template_delete_title: 'Delete template',
        template_default_badge: 'Default',
        placeholders_count: 'placeholders',
    },
    am: {
        case_prefix: 'መዝገብ',
        header_preview: 'የራስጌ ቅድመ-እይታ',
        footer_preview: 'የግርጌ ቅድመ-እይታ',
        output_new_tab: 'PDF-ው በአዲስ ትር ይከፈታል።',
        no_case_selected: 'ተከራካሪ ወገኖችን ለመሙላት መዝገብ ይምረጡ።',
        review_delete_title: 'ግምገማ ሰርዝ',
        template_delete_title: 'አብነት ሰርዝ',
        template_default_badge: 'ነባሪ',
        placeholders_count: 'ቦታ ያዢዎች',
    },
} as const;

export type DecisionFormsTextKey = keyof typeof decisionForms.en;

export default decisionForms;
