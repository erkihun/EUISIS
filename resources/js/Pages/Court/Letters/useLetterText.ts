import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import letters, { type LetterTextKey } from '@/court/i18n/letters';

/**
 * The translator of the letter forms: `t` for cm.* keys, `text` for the
 * labels only these pages need (court/i18n/letters.ts, read through
 * `court.letterForms.*` once it is wired in), `fill` for `:name` placeholders.
 */
export function useLetterText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const text = useCallback((key: LetterTextKey): string => {
        const wired = t(`court.letterForms.${key}`);
        if (wired !== `court.letterForms.${key}`) return wired;
        return letters[lang][key] ?? letters.en[key];
    }, [t, lang]);

    /** `__('x', ['name' => y])`: the lang strings carry `:name` placeholders. */
    const fill = useCallback((template: string, values: Record<string, string>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(value), template), []);

    return { t, text, fill, locale };
}
