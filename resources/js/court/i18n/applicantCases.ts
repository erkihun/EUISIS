/**
 * Text the applicant's case pages need that no lang/{en,am}/*.php key provides
 * (the Blade pages had these words written into the template). To be merged
 * into court/i18n/{en,am}.ts under `court.applicantCases`; until then
 * court/i18n/useApplicantCaseText.ts reads it directly as the fallback.
 */
const applicantCases = {
    en: {
        review: 'Review',
        // The server accepts up to 5 MB per evidence file (ApplicantCaseController, max:5120); the old form said 2MB.
        evidence_size_note: 'Each evidence file must not exceed 5MB.',
        case_number_format_hint: '(type prefix / 5-digit sequence / last 2 digits of year).',
        remove_row: 'Remove row',
        files_hint: 'PDF, Word or image files, up to 4 MB each.',
        // The printable filing receipt
        receipt_access_code: 'Access code',
        receipt_documents: 'Documents',
        receipt_court_type: 'Court / Type',
        receipt_human_evidence: 'Human evidence / testimony',
        receipt_none_provided: 'None provided.',
        receipt_none_uploaded: 'None uploaded.',
        receipt_not_provided: 'Not provided',
        receipt_no_phone: 'No phone',
        receipt_print: 'Print receipt',
        receipt_generated_on: 'Generated on',
    },
    am: {
        review: 'ግምገማ',
        evidence_size_note: 'እያንዳንዱ ማስረጃ ፋይል ከ 5MB መብለጥ የለበትም።',
        case_number_format_hint: '(የክስ ዓይነት መለያ / ባለ 5 አሃዝ ተከታታይ ቁጥር / የዓመቱ የመጨረሻ 2 አሃዞች)።',
        remove_row: 'ረድፉን አስወግድ',
        files_hint: 'PDF፣ Word ወይም የምስል ፋይሎች፣ እያንዳንዳቸው እስከ 4 MB።',
        receipt_access_code: 'የመግቢያ ኮድ',
        receipt_documents: 'ሰነዶች',
        receipt_court_type: 'ፍርድ ቤት / ዓይነት',
        receipt_human_evidence: 'የሰው ማስረጃ / ምስክርነት',
        receipt_none_provided: 'ምንም አልቀረበም።',
        receipt_none_uploaded: 'ምንም አልተጫነም።',
        receipt_not_provided: 'አልተገለጸም',
        receipt_no_phone: 'ስልክ የለም',
        receipt_print: 'ደረሰኙን አትም',
        receipt_generated_on: 'የተዘጋጀበት ጊዜ፦',
    },
} as const;

export type ApplicantCasesTextKey = keyof typeof applicantCases.en;

export default applicantCases;
