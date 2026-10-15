import { useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { SrLabel, SrLabels } from '@/types/situationRoom';

const PREFIX = 'situationRoom.';

/**
 * The Situation Room's words. They come from lang/{en,am}/situation_room.php
 * through the page's `labels` prop — the same dictionary the PDF and Excel
 * exports use — and are resolved here in the visitor's language. A missing
 * key falls back to English, then to a readable form of the key itself, so a
 * gap shows as words, never as a blank.
 */
export function useSituationLabels() {
    const { locale } = useLocale();
    const labels = (usePage().props as { labels?: SrLabels }).labels;

    const sr = useCallback((key: string, params?: Record<string, string | number>): string => {
        const short = key.startsWith(PREFIX) ? key.slice(PREFIX.length) : key;
        const text = labels?.[locale === 'am' ? 'am' : 'en']?.[short] ?? labels?.en?.[short] ?? humanize(short);

        return params
            ? Object.entries(params).reduce((out, [name, value]) => out.split(`:${name}`).join(String(value)), text)
            : text;
    }, [labels, locale]);

    const label = useCallback((value: SrLabel | null | undefined): string => {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value;
        if ('key' in value) return sr(value.key);
        const order = locale === 'am' ? [value.am, value.en] : [value.en, value.am];

        return order.find((part) => part !== null && part.trim() !== '') ?? '';
    }, [locale, sr]);

    return { sr, label, locale };
}

function humanize(key: string): string {
    const last = key.split('.').filter((part) => part !== 'title').pop() ?? key;

    return last.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}
