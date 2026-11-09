import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import access, { type AccessFormsKey } from './access';

/**
 * The access forms' own labels: `court.accessForms.<key>` once the dictionary
 * carries them, the bundled access.ts text in the current locale until then.
 * `replace` fills `:name` placeholders.
 */
export function useAccessForms() {
    const { t, locale } = useLocale();

    return useCallback((key: AccessFormsKey, replace: Record<string, string | number> = {}): string => {
        const path = `court.accessForms.${key}`;
        const wired = t(path);
        const table = locale === 'am' ? access.am : access.en;
        let text = wired !== path ? wired : (table[key] ?? access.en[key]);
        for (const [name, value] of Object.entries(replace)) {
            text = text.replace(`:${name}`, String(value));
        }
        return text;
    }, [t, locale]);
}

/** `:name` placeholders in a lang string, the way `__()` fills them on the server. */
export function fill(text: string, replace: Record<string, string | number>): string {
    let out = text;
    for (const [name, value] of Object.entries(replace)) {
        out = out.replace(`:${name}`, String(value));
    }
    return out;
}

/** A `one|many` lang string chosen by count, with `{count}` filled in (`trans_choice`). */
export function choice(text: string, count: number): string {
    const forms = text.split('|');
    const form = forms.length > 1 && count !== 1 ? forms[1] : forms[0];
    return form.replace(/\{count\}/g, String(count)).replace(':count', String(count));
}
