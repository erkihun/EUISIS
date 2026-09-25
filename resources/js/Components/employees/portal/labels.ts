import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';

/**
 * Portal strings arrive from the server in both languages (lang/{en,am}/
 * employee-portal.php) because the language is switched in the browser.
 * This picks the active set, falling back to English per key.
 */
export type PortalLabelSet = Record<string, unknown>;
export type BilingualLabels = { en: PortalLabelSet; am: PortalLabelSet };

function lookup(set: PortalLabelSet | undefined, path: string): string | undefined {
    const value = path.split('.').reduce<unknown>(
        (node, key) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[key] : undefined),
        set,
    );
    return typeof value === 'string' ? value : undefined;
}

export function usePortalLabels(labels: BilingualLabels) {
    const { locale } = useLocale();
    const active = locale === 'am' ? labels.am : labels.en;

    const l = useCallback(
        (path: string, params: Record<string, string> = {}): string => {
            const text = lookup(active, path) ?? lookup(labels.en, path) ?? path;
            return Object.entries(params).reduce((out, [key, value]) => out.split(`:${key}`).join(value), text);
        },
        [active, labels.en],
    );

    /** Pick the Amharic reading of a bilingual value when the portal is in Amharic. */
    const pick = useCallback(
        (en: string | null | undefined, am: string | null | undefined): string => (locale === 'am' && am ? am : en ?? am ?? ''),
        [locale],
    );

    return { l, pick, locale };
}
