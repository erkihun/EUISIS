import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import respondentPages, { type RespondentPagesTextKey } from '@/court/i18n/respondent';

/**
 * The translator of the respondent-portal pages: `t` for cm.* keys, `tx` for a key with a fallback when it is
 * missing from the dictionary, `text` for the few labels only these pages need (court/i18n/respondent.ts, read
 * through `court.respondentPages.*` once it is wired in), `fill` for `:placeholder` replacement as `__()` does.
 */
export function useRespondentText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template), []);

    const text = useCallback((key: RespondentPagesTextKey, values?: Record<string, string | number>): string => {
        const path = `court.respondentPages.${key}`;
        const wired = t(path);
        const value = wired !== path ? wired : (respondentPages[lang][key] ?? respondentPages.en[key]);
        return values ? fill(value, values) : value;
    }, [t, lang, fill]);

    return { t, tx, text, fill, locale, lang };
}
