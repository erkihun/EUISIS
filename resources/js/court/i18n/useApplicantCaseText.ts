import { useCallback } from 'react';
import type { Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import applicantCases, { type ApplicantCasesTextKey } from '@/court/i18n/applicantCases';

/** The tone of each case status, as the Blade portal coloured its chips. */
export const CASE_STATUS_TONES: Record<string, Tone> = {
    pending: 'warning', active: 'info', adjourned: 'neutral', dismissed: 'neutral', closed: 'neutral',
};

/** The tone of each review state (a case, a reply to a respondent response). */
export const REVIEW_STATUS_TONES: Record<string, Tone> = {
    awaiting_review: 'warning', returned: 'warning', rejected: 'danger', accepted: 'success',
};

/** The tone of a letter's approval state. */
export const LETTER_STATUS_TONES: Record<string, Tone> = {
    approved: 'success', returned: 'warning', rejected: 'danger',
};

/**
 * The translator of the applicant's case and reply pages: `t` for cm.* keys,
 * `tx` for a cm.* key with a fallback when the key is missing, `text` for the
 * few labels only these pages need (court/i18n/applicantCases.ts, read
 * through `court.applicantCases.*` once it is wired in), `fill` for
 * `:placeholder` replacement, and the status/review labels.
 */
export function useApplicantCaseText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template), []);

    const text = useCallback((key: ApplicantCasesTextKey): string => {
        const wired = t(`court.applicantCases.${key}`);
        if (wired !== `court.applicantCases.${key}`) return wired;
        return applicantCases[lang][key] ?? applicantCases.en[key];
    }, [t, lang]);

    const statusLabel = useCallback((status: string): string => tx(`cm.cases.status.${status}`, status), [tx]);
    /** An unknown review state reads as accepted, as on the Blade pages. */
    const reviewLabel = useCallback((status: string): string => tx(`cm.cases.review_status.${status}`, t('cm.cases.review_status.accepted')), [tx, t]);

    return { t, tx, text, fill, locale, lang, statusLabel, reviewLabel };
}
