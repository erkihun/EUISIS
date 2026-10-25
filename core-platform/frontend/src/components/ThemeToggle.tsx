import { useMemo, useState } from 'react';
import { Moon, Sun } from './NavIcons';
import { useLocale } from '../i18n';
import { getActiveTheme, getStoredThemePreference, setThemePreference, type ThemePreference } from '../lib/theme';
import { useSettings } from '../settings';

const order: ThemePreference[] = ['system', 'light', 'dark'];

/** EUISIS Components/ThemeToggle.tsx: system → light → dark, hidden when Core settings forbid switching. */
export default function ThemeToggle({ variant = 'default' }: { variant?: 'default' | 'toolbar' }) {
    const { t } = useLocale();
    const { getBoolean, getString } = useSettings();
    const allowSwitching = getBoolean('appearance.allow_user_theme_switching', true);
    const defaultTheme = getString('appearance.default_theme', 'system') as ThemePreference;
    const [preference, setPreference] = useState<ThemePreference>(() => getStoredThemePreference() ?? defaultTheme);

    const nextPreference = useMemo(() => order[(order.indexOf(preference) + 1) % order.length], [preference]);
    const activeTheme = getActiveTheme(preference);

    if (!allowSwitching) return null;

    const cycleTheme = () => {
        setThemePreference(nextPreference);
        setPreference(nextPreference);
    };

    const icon = activeTheme === 'dark'
        ? <Sun className="h-5 w-5" aria-hidden="true" />
        : <Moon className="h-5 w-5" aria-hidden="true" />;

    return (
        <button
            type="button"
            onClick={cycleTheme}
            className={variant === 'toolbar'
                ? 'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-(--app-muted-foreground) transition-colors duration-150 hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary'
                : 'rounded-lg p-2 text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground)'}
            aria-label={`${t('settings.themeMode')}: ${t(`common.${preference}`)}`}
            title={`${t('settings.themeMode')}: ${t(`common.${preference}`)}`}
        >
            {icon}
        </button>
    );
}
