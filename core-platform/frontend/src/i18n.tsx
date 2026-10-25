import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { am } from './lang/am';
import { en, type MessageKey } from './lang/en';

export type Lang = 'en' | 'am';

const dictionaries: Record<Lang, Record<MessageKey, string>> = { en, am };
const LANG_KEY = 'core.lang';

type I18n = {
    lang: Lang;
    toggle: () => void;
    t: (key: MessageKey, params?: Record<string, string | number>) => string;
    status: (value: string | null | undefined) => string;
    date: (value: string | null | undefined) => string;
};

const I18nContext = createContext<I18n | null>(null);

function storedLang(): Lang {
    try {
        return localStorage.getItem(LANG_KEY) === 'am' ? 'am' : 'en';
    } catch {
        return 'en';
    }
}

export function I18nProvider({ children }: { children: ReactNode }) {
    const [lang, setLang] = useState<Lang>(storedLang);

    useEffect(() => {
        document.documentElement.lang = lang;
        try {
            localStorage.setItem(LANG_KEY, lang);
        } catch {
            // Remembering the language is a convenience only.
        }
    }, [lang]);

    const t = useCallback<I18n['t']>(
        (key, params) => {
            let text = dictionaries[lang][key] ?? en[key] ?? key;
            for (const [name, value] of Object.entries(params ?? {})) text = text.replaceAll(`{${name}}`, String(value));
            return text;
        },
        [lang],
    );

    const value = useMemo<I18n>(
        () => ({
            lang,
            toggle: () => setLang((current) => (current === 'en' ? 'am' : 'en')),
            t,
            status: (raw) => {
                if (!raw) return '—';
                const key = `status.${raw}` as MessageKey;
                return key in en ? t(key) : raw.replaceAll('_', ' ');
            },
            date: (raw) => (raw ? raw.slice(0, 10) : '—'),
        }),
        [lang, t],
    );

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18n {
    const context = useContext(I18nContext);
    if (context === null) throw new Error('useI18n outside I18nProvider');
    return context;
}
