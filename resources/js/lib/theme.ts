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

/*
 * Interface style (System Settings › Appearance › Interface style). The admin
 * choice is the default; when user theme switching is allowed, a person may
 * pick their own, kept in localStorage like the light/dark preference and
 * applied before first paint by app.blade.php.
 */
export type UiStyle = 'swiss' | 'classic' | 'soft' | 'contrast';
export const UI_STYLES: readonly UiStyle[] = ['swiss', 'classic', 'soft', 'contrast'];
const UI_STYLE_KEY = 'ui-style';

export function isUiStyle(value: unknown): value is UiStyle {
    return typeof value === 'string' && (UI_STYLES as readonly string[]).includes(value);
}

export function getStoredUiStyle(): UiStyle | null {
    if (typeof window === 'undefined') return null;
    try {
        const stored = localStorage.getItem(UI_STYLE_KEY);
        return isUiStyle(stored) ? stored : null;
    } catch {
        return null;
    }
}

export function applyUiStyle(style: UiStyle): void {
    if (typeof document === 'undefined') return;
    document.documentElement.dataset.uiStyle = style;
}

/** The style to show: the person's own when switching is allowed, else the organisation's. */
export function resolveUiStyle(adminStyle: unknown, allowUserSwitching: boolean): UiStyle {
    const fallback: UiStyle = isUiStyle(adminStyle) ? adminStyle : 'swiss';
    return (allowUserSwitching ? getStoredUiStyle() : null) ?? fallback;
}

/** `null` clears the personal choice and goes back to the organisation default. */
export function setUiStylePreference(style: UiStyle | null, adminStyle: unknown): void {
    if (typeof window !== 'undefined') {
        try {
            if (style) localStorage.setItem(UI_STYLE_KEY, style);
            else localStorage.removeItem(UI_STYLE_KEY);
        } catch {
            // Storage unavailable: the choice still applies for this visit.
        }
    }
    applyUiStyle(style ?? (isUiStyle(adminStyle) ? adminStyle : 'swiss'));
}

export function initUiStyle(adminStyle: unknown, allowUserSwitching = true): UiStyle {
    const style = resolveUiStyle(adminStyle, allowUserSwitching);
    applyUiStyle(style);
    return style;
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
