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

type Loader = () => Promise<{ default: TranslationTree }>;

const loaders: Record<Locale, Loader> = {
    en: () => import('./bundles/en'),
    am: () => import('./bundles/am'),
};

/**
 * The court module's dictionary (ADR 0020) joins as two namespaces of its own:
 * `court.*` (its screens' text) and `cm.*` (its lang files, exported by
 * `php artisan court:client-lang`). It is about a dozen chunks beside the
 * language bundle, and most people never open the court, so it is fetched only
 * where it is read: app.tsx awaits it before a `Court/` page renders and the
 * sidebar asks for it when the court menu is shown (`ensureCourtLocale`). Once
 * asked for, it follows every later language switch (`ensureLocale`), so a
 * court screen never shows raw keys after the language changes. Nothing else
 * of it reaches the platform's keys.
 */
const courtLoaders: Record<Locale, Loader> = {
    en: () => import('@/court/i18n/en'),
    am: () => import('@/court/i18n/am'),
};

const loaded: Partial<Record<Locale, TranslationTree>> = {};
const pending: Partial<Record<Locale, Promise<TranslationTree>>> = {};
const courtLoaded: Partial<Record<Locale, true>> = {};
const courtPending: Partial<Record<Locale, Promise<TranslationTree>>> = {};
let courtRequested = false;

/**
 * Bumped whenever a dictionary in memory changes (a court tree merged in).
 * `useTranslator` subscribes, so a screen already on the page re-renders with
 * the new text instead of keeping the keys it rendered a moment earlier.
 */
let version = 0;
const listeners = new Set<() => void>();

function notify(): void {
    version += 1;
    for (const listener of listeners) listener();
}

export function subscribeTranslations(listener: () => void): () => void {
    listeners.add(listener);
    return () => { listeners.delete(listener); };
}

export function translationsVersion(): number {
    return version;
}

export function isLocaleLoaded(locale: Locale): boolean {
    return loaded[locale] !== undefined;
}

export function isCourtLocaleLoaded(locale: Locale): boolean {
    return courtLoaded[locale] === true;
}

function ensureBase(locale: Locale): Promise<TranslationTree> {
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

function ensureCourt(locale: Locale): Promise<TranslationTree> {
    if (courtLoaded[locale]) return Promise.resolve(loaded[locale] as TranslationTree);

    const inFlight = courtPending[locale];
    if (inFlight) return inFlight;

    const promise = Promise.all([ensureBase(locale), courtLoaders[locale]()])
        .then(([tree, { default: courtTree }]) => {
            const merged: TranslationTree = { ...tree, court: courtTree.court, cm: courtTree.cm };
            loaded[locale] = merged;
            courtLoaded[locale] = true;
            notify();
            return merged;
        })
        .finally(() => {
            delete courtPending[locale];
        });
    courtPending[locale] = promise;

    return promise;
}

/** The language's dictionary; includes the court's tree once that has been asked for in this session. */
export function ensureLocale(locale: Locale): Promise<TranslationTree> {
    return courtRequested ? ensureCourt(locale) : ensureBase(locale);
}

/** The language's dictionary with the court's `court.*` and `cm.*` merged in. */
export function ensureCourtLocale(locale: Locale): Promise<TranslationTree> {
    courtRequested = true;
    return ensureCourt(locale);
}

/** The dictionary for a language; falls back to whichever language is loaded, then to an empty tree. */
export function translationsFor(locale: Locale): TranslationTree {
    return loaded[locale] ?? loaded.en ?? loaded.am ?? {};
}
