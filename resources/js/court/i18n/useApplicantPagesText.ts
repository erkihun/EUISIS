import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import applicantPages, { type ApplicantPagesTextKey } from '@/court/i18n/applicant';

/**
 * The translator of the applicant account pages: `t` for cm.* keys, `tx` for
 * a key with a fallback when the dictionary does not have it, `fill` for
 * `:placeholder` replacement, `text` for the few labels only these pages need
 * (court/i18n/applicant.ts, read through `court.applicantPages.*` once it is
 * wired into the dictionary).
 */
export function useApplicantPagesText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template), []);

    const text = useCallback((key: ApplicantPagesTextKey, values?: Record<string, string | number>): string => {
        const wired = t(`court.applicantPages.${key}`);
        const value = wired !== `court.applicantPages.${key}` ? wired : (applicantPages[lang][key] ?? applicantPages.en[key]);
        return values ? fill(value, values) : value;
    }, [t, lang, fill]);

    return { t, tx, text, fill, locale, lang };
}
