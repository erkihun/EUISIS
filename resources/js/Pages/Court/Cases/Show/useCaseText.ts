import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import caseDetail, { type CaseDetailTextKey } from '@/court/i18n/caseDetail';
import type { Tone } from '@/Components/ui';

/** The tone of each case status, as the Blade page coloured its chips. */
export const STATUS_TONES: Record<string, Tone> = {
    pending: 'warning', active: 'info', adjourned: 'neutral', dismissed: 'danger', closed: 'success',
};

/** The tone of each review state (case, respondent response, reply). */
export const REVIEW_TONES: Record<string, Tone> = {
    awaiting_review: 'warning', returned: 'warning', rejected: 'danger', accepted: 'success',
};

/** The tone of a letter's approval state. */
export const LETTER_TONES: Record<string, Tone> = {
    pending: 'warning', approved: 'success', rejected: 'danger', returned: 'warning',
};

/**
 * The translator of the case page: `t` for cm.* keys, `tx` for a cm.* key
 * with a fallback when the key is missing from the dictionary, `text` for the
 * handful of labels only this page needs (court/i18n/caseDetail.ts, read
 * through `court.caseDetail.*` once it is wired in).
 */
export function useCaseText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const text = useCallback((key: CaseDetailTextKey): string => {
        const wired = t(`court.caseDetail.${key}`);
        if (wired !== `court.caseDetail.${key}`) return wired;
        return caseDetail[lang][key] ?? caseDetail.en[key];
    }, [t, lang]);

    const statusLabel = useCallback((status: string): string => tx(`cm.cases.status.${status}`, status), [tx]);
    const reviewLabel = useCallback((status: string): string => tx(`cm.cases.review_status.${status}`, tx('cm.cases.review_status.accepted', status)), [tx]);
    /** `__('x', ['name' => y])`: the lang strings carry `:name` placeholders. */
    const fill = useCallback((template: string, values: Record<string, string>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(value), template), []);

    return { t, tx, text, locale, statusLabel, reviewLabel, fill };
}
