import { useEffect } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

function applyLocaleToDocument(locale: string) {
    document.documentElement.lang = locale;
    document.body.classList.toggle('locale-am', locale === 'am');
}

export default function LanguageSwitcher() {
    const { locale, setLocale, localeOptions } = useLocale();
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
            className="flex items-center gap-0.5 rounded-lg border border-gray-200 p-0.5 dark:border-slate-700"
            role="group"
            aria-label="Language selector"
        >
            {localeOptions.map((opt) => (
                <button
                    key={opt.value}
                    type="button"
                    onClick={() => handleSelect(opt.value as 'en' | 'am')}
                    className={[
                        'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors',
                        locale === opt.value
                            ? 'bg-[color:var(--color-primary)] text-white'
                            : 'text-gray-500 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100',
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
