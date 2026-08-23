import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import en from '@/i18n/en';
import am from '@/i18n/am';

export type Locale = 'en' | 'am';

type TranslationTree = { [key: string]: string | TranslationTree };

const DICTIONARIES: Record<Locale, TranslationTree> = {
    en: en as TranslationTree,
    am: am as TranslationTree,
};

/**
 * Resolve a dotted key path, returning null when it is missing so the caller
 * can fall back rather than render `undefined`.
 */
function lookup(tree: TranslationTree, path: string): string | null {
    let current: unknown = tree;

    for (const segment of path.split('.')) {
        if (current === null || typeof current !== 'object') {
            return null;
        }

        current = (current as TranslationTree)[segment];
    }

    return typeof current === 'string' ? current : null;
}

/**
 * Interface translation, driven by the locale the server applied for this
 * request. The choice is persisted server-side in the session, so a reload or
 * a second tab shows the same language.
 */
export function useLocale() {
    const page = usePage<{ locale?: string; supported_locales?: string[] }>();

    const locale: Locale = page.props.locale === 'am' ? 'am' : 'en';
    const supported = (page.props.supported_locales ?? ['en', 'am']).filter(
        (code): code is Locale => code === 'en' || code === 'am',
    );

    const t = useCallback(
        (key: string): string =>
            // Fall back to English before the raw key, so a gap in the Amharic
            // dictionary still reads as words rather than `scan.qrToken`.
            lookup(DICTIONARIES[locale], key) ?? lookup(DICTIONARIES.en, key) ?? key,
        [locale],
    );

    const setLocale = useCallback((next: Locale) => {
        router.post('/locale', { locale: next }, { preserveScroll: true, preserveState: false });
    }, []);

    const localeOptions = useMemo(
        () => supported.map((code) => ({ value: code, label: code === 'am' ? 'አማ' : 'EN' })),
        [supported],
    );

    return { locale, setLocale, localeOptions, t, isAmharic: locale === 'am' };
}
