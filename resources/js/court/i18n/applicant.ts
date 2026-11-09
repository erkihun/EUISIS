/**
 * Text the React applicant account pages (sign-in, registration, password
 * reset, email verification, dashboard, profile, sessions, notifications)
 * need that no lang/{en,am}/*.php key provides. To be merged into
 * court/i18n/{en,am}.ts under `court.applicantPages`; until then
 * court/i18n/useApplicantPagesText.ts reads it directly as the fallback.
 */
const applicantPages = {
    en: {
        // The six boxes of a one-time code, read out one by one.
        otp_digit: 'Digit :n of :total',
        // The +251 prefix in front of the registration phone number.
        phone_country_code: 'Ethiopia (+251)',
    },
    am: {
        otp_digit: 'አሃዝ :n ከ:total',
        phone_country_code: 'ኢትዮጵያ (+251)',
    },
} as const;

export type ApplicantPagesTextKey = keyof typeof applicantPages.en;

export default applicantPages as { en: Record<ApplicantPagesTextKey, string>; am: Record<ApplicantPagesTextKey, string> };
