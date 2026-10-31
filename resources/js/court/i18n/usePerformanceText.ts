import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import performancePages, { type PerformancePagesTextKey } from '@/court/i18n/performance';

/**
 * The translator of the performance-evaluation pages: `t` for cm.* keys
 * (lang/{en,am}/performance.php is `cm.performance.*`), `text` for the few
 * labels only these pages need (court/i18n/performance.ts, read through
 * `court.performancePages.*` once it is wired in), `fill` for `:placeholder`
 * replacement the way `__()` does it on the server.
 */
export function usePerformanceText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const fill = useCallback((template: string, values: Record<string, string | number>): string =>
        template.replace(/:([a-z_]+)/g, (match, name: string) => (name in values ? String(values[name]) : match)), []);

    const text = useCallback((key: PerformancePagesTextKey, values?: Record<string, string | number>): string => {
        const path = `court.performancePages.${key}`;
        const wired = t(path);
        const value = wired !== path ? wired : (performancePages[lang][key] ?? performancePages.en[key]);
        return values ? fill(value, values) : value;
    }, [t, lang, fill]);

    /** A cm.performance.* key. */
    const p = useCallback((key: string, values?: Record<string, string | number>): string => {
        const value = t(`cm.performance.${key}`);
        return values ? fill(value, values) : value;
    }, [t, fill]);

    return { t, p, text, fill, locale, lang };
}
