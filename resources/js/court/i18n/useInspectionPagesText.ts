import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import inspectionPages, { type InspectionPagesTextKey } from '@/court/i18n/inspections';

/**
 * The translator of the inspection, bench-note and record pages: `t` for cm.*
 * keys, `tx` for a cm.* key with a fallback when the key is missing from the
 * dictionary, `text` for the handful of labels only these pages need
 * (court/i18n/inspections.ts, read through `court.inspectionPages.*` once it
 * is wired in), `fill` for `:placeholder` replacement.
 */
export function useInspectionPagesText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const tx = useCallback((key: string, fallback: string): string => {
        const value = t(key);
        return value === key ? fallback : value;
    }, [t]);

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template), []);

    const text = useCallback((key: InspectionPagesTextKey, values?: Record<string, string | number>): string => {
        const wired = t(`court.inspectionPages.${key}`);
        const value = wired !== `court.inspectionPages.${key}` ? wired : (inspectionPages[lang][key] ?? inspectionPages.en[key]);
        return values ? fill(value, values) : value;
    }, [t, lang, fill]);

    return { t, tx, text, fill, locale, lang };
}
