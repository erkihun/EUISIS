import { useLocale } from '@/hooks/useLocale';

/**
 * Reads a bilingual CMS field (`title_en` / `title_am`) in the visitor's
 * language, falling back to the other language when one side is empty.
 *
 * Public Site Management stores both languages side by side and the server
 * sends both, because the active locale lives in the browser. The fallback
 * means an administrator who has only written English yet does not leave an
 * Amharic reader looking at a blank heading — they see the English text
 * rather than nothing, and never a mix within one field.
 */
export function useBilingual() {
    const { locale } = useLocale();
    const primary = locale === 'am' ? 'am' : 'en';
    const secondary = primary === 'am' ? 'en' : 'am';

    return function pick(record: object | null | undefined, field: string): string {
        if (!record) return '';

        const source = record as Record<string, unknown>;
        const first = source[`${field}_${primary}`];
        if (typeof first === 'string' && first.trim() !== '') return first;

        const second = source[`${field}_${secondary}`];
        return typeof second === 'string' ? second : '';
    };
}
