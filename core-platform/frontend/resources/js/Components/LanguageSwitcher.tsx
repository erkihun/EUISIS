import { useEffect } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

function applyLocaleToDocument(locale: string) {
    document.documentElement.lang = locale;
    document.body.classList.toggle('locale-am', locale === 'am');
}

export default function LanguageSwitcher({ variant = 'default' }: { variant?: 'default' | 'toolbar' }) {
    const { locale, setLocale, localeOptions, t } = useLocale();
    const { getBoolean } = useSystemSettings();

    useEffect(() => {
        applyLocaleToDocument(locale);
    }, [locale]);

    if (! getBoolean('appearance.show_language_switcher', true)) return null;
    if (localeOptions.length <= 1) return null;

    function handleSelect(next: 'en' | 'am') {
        setLocale(next);
        applyLocaleToDocument(next);
    }

    return (
        <div
            className={variant === 'toolbar' ? 'flex h-9 shrink-0 items-center gap-0.5 rounded-md bg-(--app-surface-muted) p-0.5' : 'flex items-center gap-0.5 rounded-lg border border-(--app-border) p-0.5'}
            role="group"
            aria-label={t('common.language')}
        >
            {localeOptions.map((opt) => (
                <button
                    key={opt.value}
                    type="button"
                    onClick={() => handleSelect(opt.value as 'en' | 'am')}
                    className={[
                        'rounded-sm text-xs font-semibold transition-colors duration-150 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                        variant === 'toolbar' ? 'flex h-8 min-w-8 items-center justify-center px-1.5' : 'px-2.5 py-1',
                        locale === opt.value
                            ? variant === 'toolbar' ? 'bg-(--app-surface) text-(--app-foreground) shadow-xs' : 'bg-primary text-white'
                            : 'text-(--app-muted-foreground) hover:text-(--app-foreground)',
                    ].join(' ')}
                    aria-pressed={locale === opt.value}
                    aria-label={opt.value === 'am' ? 'Amharic' : 'English'}
                >
                    {opt.label}
                </button>
            ))}
        </div>
    );
}
