import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import publicPages, { type PublicPagesTextKey } from '@/court/i18n/public';

/**
 * The translator of the public pages, the landing-page manager and the staff
 * account pages: `t` for cm.* keys, `tx` for a key with a fallback when it is
 * missing from the dictionary, `text` for the labels only these pages need
 * (court/i18n/public.ts, read through `court.publicPages.*` once it is wired
 * in), `fill` for `:placeholder` replacement.
 */
export function usePublicText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template), []);

    const text = useCallback((key: PublicPagesTextKey, values?: Record<string, string | number>): string => {
        const wired = t(`court.publicPages.${key}`);
        const value = wired !== `court.publicPages.${key}` ? wired : (publicPages[lang][key] ?? publicPages.en[key]);
        return values ? fill(value, values) : value;
    }, [t, lang, fill]);

    return { t, tx, text, fill, locale, lang };
}
