import { useEffect, useSyncExternalStore } from 'react';
import { useLocaleContext } from '@/contexts/LocaleContext';
import { ensureCourtLocale, isCourtLocaleLoaded, subscribeTranslations, translationsVersion } from '@/i18n/registry';

/**
 * Fetches the court module's dictionary (`court.*`, `cm.*`) for the current
 * language when `wanted` is true and says whether it is in memory. It is not
 * part of the language bundle (see resources/js/i18n/registry.ts): a screen
 * outside the court that labels something with the court's keys, such as the
 * sidebar's Tribunal Court group, asks for it here and keeps those labels off
 * the page until the answer is true, so no raw key is ever shown.
 */
export function useCourtTranslations(wanted: boolean): boolean {
    const { locale } = useLocaleContext();
    // Re-render when the tree lands, whichever component asked for it.
    useSyncExternalStore(subscribeTranslations, translationsVersion, translationsVersion);

    useEffect(() => {
        if (!wanted || isCourtLocaleLoaded(locale)) return;
        void ensureCourtLocale(locale).catch((error: unknown) => console.error('Court translations failed to load', error));
    }, [wanted, locale]);

    return isCourtLocaleLoaded(locale);
}
