import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import { useLocaleContext } from '@/contexts/LocaleContext';
import { translationsFor, type Locale, type TranslationTree } from '@/i18n/registry';
import { brandNameFor, type Branding } from '@/lib/branding';
import type { PageProps } from '@/types';

// Dictionaries live in resources/js/i18n/bundles/{en,am}.ts and are loaded one
// language at a time (see resources/js/i18n/registry.ts).

function getNestedValue(tree: TranslationTree, path: string): string {
    const segments = path.split('.');
    let current: unknown = tree;

    for (const segment of segments) {
        if (current === null || typeof current !== 'object') {
            return path;
        }

        current = (current as TranslationTree)[segment];
    }

    return typeof current === 'string' ? current : path;
}

/**
 * Locale + translator without Inertia page props, for components mounted
 * outside <App> (e.g. SessionTimeoutManager), where usePage() throws.
 */
export function useTranslator() {
    const { locale, setLocale } = useLocaleContext();

    const t = useCallback(
        (key: string): string => getNestedValue(translationsFor(locale), key),
        [locale],
    );

    return { locale, setLocale, t };
}

export function useLocale() {
    const { locale, setLocale, t: translate } = useTranslator();
    const page = usePage<PageProps<{ locale?: string; settings?: Record<string, unknown>; branding?: Partial<Branding> }>>();

    // Translations name the product as `{brand}`: አገልጋይ on Amharic pages, Agelgay elsewhere (docs/branding.md).
    const brand = brandNameFor(locale, page.props.settings ?? {}, page.props.branding ?? {});
    const t = useCallback(
        (key: string): string => {
            const text = translate(key);
            return text.includes('{brand}') ? text.replace(/\{brand\}/g, brand) : text;
        },
        [translate, brand],
    );
    const supportedLocales = ((page.props.settings?.['localization.supported_locales'] as string[] | undefined) ?? ['en', 'am'])
        .filter((code): code is Locale => code === 'en' || code === 'am');

    const localeOptions = useMemo(
        () => supportedLocales.map((code) => ({ value: code, label: code === 'am' ? 'አማ' : 'EN' })),
        [supportedLocales],
    );

    return { locale, setLocale, t, localeOptions };
}
