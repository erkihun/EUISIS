import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';

type Locale = 'en' | 'am';

/**
 * The server reads this cookie (SetClientLocale middleware) to write its own
 * text — validation errors, notices, computed problems — in the same language.
 */
function shareWithServer(locale: Locale): void {
    try {
        document.cookie = `euisis_locale=${locale}; path=/; max-age=31536000; SameSite=Lax`;
    } catch {
        // cookies unavailable
    }
}

type LocaleContextValue = {
    locale: Locale;
    setLocale: (l: Locale) => void;
};

const STORAGE_KEY = 'euisis_locale';

function getInitialLocale(): Locale {
    try {
        const stored = localStorage.getItem(STORAGE_KEY) as Locale | null;
        if (stored === 'en' || stored === 'am') return stored;
    } catch {
        // localStorage unavailable (SSR / private mode)
    }
    return 'am';
}

export const LocaleContext = createContext<LocaleContextValue>({
    locale: 'am',
    setLocale: () => {},
});

type LocaleProviderProps = {
    children: ReactNode;
    defaultLocale?: Locale;
};

export function LocaleProvider({ children, defaultLocale = 'am' }: LocaleProviderProps) {
    const [locale, setLocaleState] = useState<Locale>(() => getInitialLocale() ?? defaultLocale);

    const current = useRef(locale);
    useEffect(() => { current.current = locale; }, [locale]);

    const setLocale = useCallback((next: Locale) => {
        const changed = next !== current.current;
        try { localStorage.setItem(STORAGE_KEY, next); } catch { /* ignore */ }
        shareWithServer(next);
        setLocaleState(next);
        // Refetch the page's server-written text (problems, messages) in the new language.
        if (changed) router.reload();
    }, []);

    useEffect(() => {
        document.documentElement.lang = locale;
        document.body.classList.toggle('locale-am', locale === 'am');
        shareWithServer(locale);
    }, [locale]);

    return (
        <LocaleContext.Provider value={{ locale, setLocale }}>
            {children}
        </LocaleContext.Provider>
    );
}

export function useLocaleContext(): LocaleContextValue {
    return useContext(LocaleContext);
}
