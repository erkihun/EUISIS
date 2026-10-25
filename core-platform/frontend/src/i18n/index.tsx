import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { am } from './am';
import { en } from './en';

export type Locale = 'en' | 'am';

/** The product name, as EUISIS shows it (lib/branding.ts fallbacks). */
export const BRAND: Record<Locale, string> = { en: 'Agelgay', am: 'አገልጋይ' };

const dictionaries: Record<Locale, object> = { en, am };
const LOCALE_KEY = 'core.locale';

type Params = Record<string, string | number>;

type LocaleContext = {
    locale: Locale;
    setLocale: (locale: Locale) => void;
    localeOptions: { value: Locale; label: string }[];
    /** A dotted key (`core.nav.dashboard`); `{brand}`, `:name` and `{name}` are filled in. Unknown keys come back as the key, as in EUISIS. */
    t: (key: string, params?: Params) => string;
};

const Context = createContext<LocaleContext | null>(null);

function lookup(dictionary: object, key: string): string | undefined {
    const value = key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), dictionary);
    return typeof value === 'string' ? value : undefined;
}

function storedLocale(): Locale {
    try {
        return localStorage.getItem(LOCALE_KEY) === 'am' ? 'am' : 'en';
    } catch {
        return 'en';
    }
}

export function LocaleProvider({ children }: { children: ReactNode }) {
    const [locale, setLocale] = useState<Locale>(storedLocale);

    useEffect(() => {
        // The typography tokens switch on <html lang>, exactly as in EUISIS.
        document.documentElement.lang = locale;
        document.body.classList.toggle('locale-am', locale === 'am');
        try {
            localStorage.setItem(LOCALE_KEY, locale);
        } catch {
            // Remembering the language is a convenience only.
        }
    }, [locale]);

    const t = useCallback<LocaleContext['t']>((key, params) => {
        let text = lookup(dictionaries[locale], key) ?? lookup(en, key) ?? key;
        text = text.replace(/\{brand\}/g, BRAND[locale]);
        for (const [name, value] of Object.entries(params ?? {})) {
            text = text.replaceAll(`{${name}}`, String(value)).replace(new RegExp(`:${name}\\b`, 'g'), String(value));
        }
        return text;
    }, [locale]);

    const value = useMemo<LocaleContext>(() => ({
        locale,
        setLocale,
        localeOptions: [{ value: 'en', label: 'EN' }, { value: 'am', label: 'አማ' }],
        t,
    }), [locale, t]);

    return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useLocale(): LocaleContext {
    const context = useContext(Context);
    if (context === null) throw new Error('useLocale outside LocaleProvider');
    return context;
}
