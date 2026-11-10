import { useEffect, useState, type SVGProps } from 'react';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
// The two icons come straight from the set, not through NavIcons: that adapter
// module carries every shell icon (about 180 KB), and the sign-in page, which
// shows this control, would otherwise download all of them for a sun and a moon.
// Same linear weight and stroke as NavIcons' adapt().
import { MoonIcon } from '@solar-icons/react/linear/moon';
import { SunIcon } from '@solar-icons/react/linear/sun';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import {
    UI_STYLES,
    getActiveTheme,
    getStoredThemePreference,
    getStoredUiStyle,
    setThemePreference,
    setUiStylePreference,
    type ThemePreference,
    type UiStyle,
} from '@/lib/theme';

const MODES: ThemePreference[] = ['system', 'light', 'dark'];

const Sun = (props: SVGProps<SVGSVGElement>) => <SunIcon strokeWidth={1.5} {...props} />;
const Moon = (props: SVGProps<SVGSVGElement>) => <MoonIcon strokeWidth={1.5} {...props} />;

const choiceClass = (selected: boolean) => [
    'flex min-h-9 w-full items-center justify-between gap-3 rounded-control px-3 text-start text-sm transition-colors duration-150',
    'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
    selected ? 'bg-(--app-surface-muted) font-semibold text-(--app-foreground)' : 'text-(--app-foreground) hover:bg-(--app-surface-muted)',
].join(' ');

/**
 * The person's appearance menu: light / dark / follow the device, and the
 * interface style (Swiss, Classic, Soft UI, High contrast) when the
 * organisation allows people to choose (Appearance › "Allow user theme
 * switching"). Both choices are kept in this browser; "organisation default"
 * returns the style to what System Settings says.
 */
export default function ThemeToggle({ variant = 'default' }: { variant?: 'default' | 'toolbar' }) {
    const { t } = useLocale();
    const { getBoolean, getString } = useSystemSettings();
    const allowSwitching = getBoolean('appearance.allow_user_theme_switching', true);
    const defaultTheme = (getString('appearance.default_theme', 'system') as ThemePreference) ?? 'system';
    const adminStyle = getString('appearance.ui_style', 'swiss');

    const [preference, setPreference] = useState<ThemePreference>(defaultTheme);
    const [ownStyle, setOwnStyle] = useState<UiStyle | null>(null);

    useEffect(() => {
        setPreference(getStoredThemePreference() ?? defaultTheme);
        setOwnStyle(getStoredUiStyle());
    }, [defaultTheme]);

    if (!allowSwitching) {
        return null;
    }

    const activeTheme = getActiveTheme(preference);
    const icon = activeTheme === 'dark'
        ? <Sun className="h-5 w-5" aria-hidden="true" />
        : <Moon className="h-5 w-5" aria-hidden="true" />;

    const chooseMode = (mode: ThemePreference) => {
        setThemePreference(mode);
        setPreference(mode);
    };

    const chooseStyle = (style: UiStyle | null) => {
        setUiStylePreference(style, adminStyle);
        setOwnStyle(style);
    };

    const label = `${t('common.appearance')}: ${t(`common.${preference}`)}`;

    return (
        <Popover className="relative">
            <PopoverButton
                className={variant === 'toolbar'
                    ? 'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-(--app-muted-foreground) transition-colors duration-150 hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary'
                    : 'rounded-lg p-2 text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground)'}
                aria-label={label}
                title={label}
            >
                {icon}
            </PopoverButton>
            <PopoverPanel
                anchor="bottom end"
                transition
                className="z-50 w-72 max-w-[calc(100vw-1rem)] rounded-panel border border-(--app-border) bg-(--app-surface) p-2 text-(--app-foreground) shadow-lg outline-hidden transition duration-100 ease-out [--anchor-gap:8px] [--anchor-padding:8px] data-closed:scale-[0.98] data-closed:opacity-0"
            >
                <p className="px-3 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('settings.themeMode')}</p>
                <div role="radiogroup" aria-label={t('settings.themeMode')} className="grid grid-cols-3 gap-1 px-1 pb-2">
                    {MODES.map((mode) => (
                        <button
                            key={mode}
                            type="button"
                            role="radio"
                            aria-checked={preference === mode}
                            onClick={() => chooseMode(mode)}
                            className={[
                                'flex h-9 items-center justify-center rounded-control border text-sm transition-colors duration-150 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                preference === mode
                                    ? 'border-primary bg-primary text-(--color-on-primary,#fff)'
                                    : 'border-(--app-border-strong) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted)',
                            ].join(' ')}
                        >
                            {t(`common.${mode}`)}
                        </button>
                    ))}
                </div>

                <p className="border-t border-(--app-border) px-3 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('common.interfaceStyle')}</p>
                <div role="radiogroup" aria-label={t('common.interfaceStyle')} className="space-y-0.5 px-1 pb-1">
                    <button type="button" role="radio" aria-checked={ownStyle === null} onClick={() => chooseStyle(null)} className={choiceClass(ownStyle === null)}>
                        <span className="min-w-0 truncate">{t('common.organizationDefaultStyle')}</span>
                        <span className="shrink-0 text-xs text-(--app-muted-foreground)">{t(`settings.options.ui_style.${adminStyle}`).split(' (')[0]}</span>
                    </button>
                    {UI_STYLES.map((style) => (
                        <button key={style} type="button" role="radio" aria-checked={ownStyle === style} onClick={() => chooseStyle(style)} className={choiceClass(ownStyle === style)}>
                            <span className="min-w-0 truncate">{t(`settings.options.ui_style.${style}`)}</span>
                        </button>
                    ))}
                </div>
            </PopoverPanel>
        </Popover>
    );
}
