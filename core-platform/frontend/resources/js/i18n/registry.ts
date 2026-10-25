/**
 * Translation dictionaries, one chunk per language.
 *
 * Both languages used to be bundled into the JavaScript every page loads
 * (about 830 KB before compression). Now a language is fetched only when it
 * is shown: app.tsx loads the first one before mounting (the HTML head
 * preloads it) and LocaleContext loads the other one on switch. Translators
 * read synchronously from `translationsFor`, which is why a language is only
 * made current after `ensureLocale` has resolved.
 */
export type Locale = 'en' | 'am';
export type TranslationTree = Record<string, unknown>;

const loaders: Record<Locale, () => Promise<{ default: TranslationTree }>> = {
    en: () => import('./bundles/en'),
    am: () => import('./bundles/am'),
};

const loaded: Partial<Record<Locale, TranslationTree>> = {};
const pending: Partial<Record<Locale, Promise<TranslationTree>>> = {};

export function isLocaleLoaded(locale: Locale): boolean {
    return loaded[locale] !== undefined;
}

export function ensureLocale(locale: Locale): Promise<TranslationTree> {
    const ready = loaded[locale];
    if (ready) return Promise.resolve(ready);

    const inFlight = pending[locale];
    if (inFlight) return inFlight;

    const promise = loaders[locale]()
        .then((module) => {
            loaded[locale] = module.default;
            return module.default;
        })
        .finally(() => {
            delete pending[locale];
        });
    pending[locale] = promise;

    return promise;
}

/** The dictionary for a language; falls back to whichever language is loaded, then to an empty tree. */
export function translationsFor(locale: Locale): TranslationTree {
    return loaded[locale] ?? loaded.en ?? loaded.am ?? {};
}
