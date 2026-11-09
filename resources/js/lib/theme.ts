export type Theme = 'light' | 'dark';
export type ThemePreference = Theme | 'system';

const STORAGE_KEY = 'theme';

export function getStoredThemePreference(): ThemePreference | null {
    if (typeof window === 'undefined') return null;
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') return stored;
    return null;
}

export function getSystemTheme(): Theme {
    if (typeof window === 'undefined') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function getActiveTheme(preference: ThemePreference = 'system'): Theme {
    return preference === 'system' ? getSystemTheme() : preference;
}

export function applyTheme(theme: Theme): void {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

export function setThemePreference(preference: ThemePreference, persist = true): void {
    if (persist && typeof window !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, preference);
    }

    applyTheme(getActiveTheme(preference));
}

export function clearStoredThemePreference(): void {
    if (typeof window === 'undefined') return;
    localStorage.removeItem(STORAGE_KEY);
}

export function initTheme(
    defaultTheme: ThemePreference = 'system',
    allowUserThemeSwitching = true,
): ThemePreference {
    const stored = allowUserThemeSwitching ? getStoredThemePreference() : null;
    const preference = stored ?? defaultTheme;

    applyTheme(getActiveTheme(preference));

    return preference;
}
