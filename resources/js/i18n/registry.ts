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

/**
 * The court module's dictionary (ADR 0020) joins as two namespaces of its own:
 * `court.*` (its screens' text) and `cm.*` (its lang files, exported by
 * `php artisan court:client-lang`). It is a chunk of its own, loaded beside the
 * language bundle; nothing else of it reaches EUISIS keys.
 */
function withCourt(base: Promise<{ default: TranslationTree }>, court: Promise<{ default: TranslationTree }>): Promise<{ default: TranslationTree }> {
    return Promise.all([base, court]).then(([{ default: tree }, { default: courtTree }]) => ({
        default: { ...tree, court: courtTree.court, cm: courtTree.cm },
    }));
}

const loaders: Record<Locale, () => Promise<{ default: TranslationTree }>> = {
    en: () => withCourt(import('./bundles/en'), import('@/court/i18n/en')),
    am: () => withCourt(import('./bundles/am'), import('@/court/i18n/am')),
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
